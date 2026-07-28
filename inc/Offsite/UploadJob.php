<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

use RhBackup\Storage\BackupKind;

/**
 * Persistenter Zustand eines Offsite-Laufs.
 *
 * Bewusst eine Option mit autoload=no und kein Transient: ein Lauf kann über Stunden
 * gehen, und ein Objekt-Cache-Flush darf den Fortschritt nicht vernichten. Sonst begänne
 * ein mehrere Gigabyte grosser Upload wieder bei null.
 *
 * Es gibt immer höchstens einen Lauf gleichzeitig, darum reicht eine feste Option ohne
 * Index. Die Kennung dient trotzdem der Zuordnung: ein verspäteter Tick eines alten
 * Laufs darf einen neuen nicht anfassen.
 */
final class UploadJob
{
    public const OPTION = 'rhbackup_offsite_job';

    public const PHASE_EXPORT = 'export';
    public const PHASE_WRITE = 'write';
    public const PHASE_SESSION = 'session';
    public const PHASE_UPLOAD = 'upload';
    public const PHASE_VERIFY = 'verify';
    public const PHASE_ROTATE = 'rotate';
    public const PHASE_DONE = 'done';
    public const PHASE_FAILED = 'failed';

    /** Ohne Lebenszeichen gilt ein Lauf als hängend und wird wiederbelebt. */
    public const STALE_AFTER = 180;

    /** Startgrösse eines Abschnitts. Google verlangt Vielfache von 256 KB. */
    public const CHUNK_SIZE = 8 * 1024 * 1024;

    public const MIN_CHUNK_SIZE = 256 * 1024;

    /** So oft darf derselbe Abschnitt scheitern, bevor der Lauf aufgibt. */
    public const MAX_RETRIES = 6;

    /**
     * @param array<string, mixed> $exportCursor Serialisierter Cursor des laufenden Exports.
     */
    private function __construct(
        public string $jobId,
        public string $spawnToken,
        public string $phase,
        public string $trigger,
        public int $createdAt,
        public int $lastUpdateAt,
        public ?int $endedAt,
        public float $tickBudget,
        public array $exportCursor,
        public string $zipPath,
        public string $fileName,
        public int $totalSize,
        public string $sessionUri,
        public int $offset,
        public int $chunkSize,
        public int $retries,
        public int $retryAfter,
        public string $fileId,
        public string $message,
        public string $error,
        public int $deletedCopies,
        public string $failedPhase = '',
        public string $mode = Settings::MODE_DRIVE,
        public string $scope = Settings::SCOPE_UPLOADS,
        public array $archiveState = [],
    ) {
    }

    /**
     * Zu welchem Anlass gehört dieser Lauf?
     *
     * Wird nicht eigens gespeichert, sondern folgt aus dem Auslöser: was der Zeitplan
     * gestartet hat, ist planmässig, alles andere von Hand.
     */
    public function kind(): string
    {
        return $this->trigger === 'schedule' ? BackupKind::AUTOMATIC : BackupKind::MANUAL;
    }

    public function isLocalMode(): bool
    {
        return $this->mode === Settings::MODE_LOCAL;
    }

    /**
     * Sichert dieser Lauf die komplette Website, also auch Themes, Plugins und Kern?
     */
    public function isFullSite(): bool
    {
        return $this->scope === Settings::SCOPE_FULL;
    }

    /**
     * @param string $trigger 'manual' oder 'schedule'
     */
    public static function create(string $trigger): self
    {
        $job = new self(
            jobId: bin2hex(random_bytes(16)),
            spawnToken: bin2hex(random_bytes(16)),
            phase: self::PHASE_EXPORT,
            trigger: $trigger,
            createdAt: time(),
            lastUpdateAt: time(),
            endedAt: null,
            tickBudget: self::budgetFromServerLimits(),
            exportCursor: [],
            zipPath: '',
            fileName: '',
            totalSize: 0,
            sessionUri: '',
            offset: 0,
            chunkSize: self::chunkFromServerLimits(),
            retries: 0,
            retryAfter: 0,
            fileId: '',
            message: __('Backup wird erstellt...', 'rh-backup'),
            error: '',
            deletedCopies: 0,
            mode: Settings::mode(),
            scope: Settings::scope(),
        );
        $job->save();

        return $job;
    }

    public static function load(): ?self
    {
        $data = get_option(self::OPTION);
        if (! is_array($data) || empty($data['job_id'])) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * Lädt einen Lauf nur, wenn die Kennung passt. Schützt davor, dass ein verspäteter
     * Tick eines abgeschlossenen Laufs den nachfolgenden anfasst.
     */
    public static function loadFor(string $jobId): ?self
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return null;
        }

        $job = self::load();

        return $job !== null && hash_equals($job->jobId, $jobId) ? $job : null;
    }

    public function save(): void
    {
        $this->lastUpdateAt = time();
        update_option(self::OPTION, $this->toArray(), false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    public function isFinished(): bool
    {
        return $this->phase === self::PHASE_DONE || $this->phase === self::PHASE_FAILED;
    }

    public function isStale(): bool
    {
        return ! $this->isFinished() && (time() - $this->lastUpdateAt) > self::STALE_AFTER;
    }

    /**
     * Wartet der Lauf gerade eine Rückzugsfrist ab?
     */
    public function isWaiting(): bool
    {
        return $this->retryAfter > time();
    }

    public function finishSuccess(string $message): void
    {
        $this->phase = self::PHASE_DONE;
        $this->message = $message;
        $this->error = '';
        $this->endedAt = time();
        $this->save();
    }

    public function finishFailure(string $error): void
    {
        // Die Phase, in der es schiefging, festhalten BEVOR sie überschrieben wird.
        // Sonst meldet die Benachrichtigung nur "fehlgeschlagen" und niemand weiss,
        // ob der Export, die Übertragung oder die Prüfung gescheitert ist.
        $this->failedPhase = $this->phase;
        $this->phase = self::PHASE_FAILED;
        $this->error = $error;
        $this->endedAt = time();
        $this->save();
    }

    /**
     * Plant den nächsten Versuch mit wachsendem Abstand.
     *
     * Google erwartet bei Überlastung und Rate-Limits ausdrücklich zunehmende Abstände.
     * Die Wartezeit wird persistiert statt abgesessen: der Tick kehrt sofort zurück, und
     * der nächste prüft die Frist. So blockiert nichts einen PHP-Prozess, und die Frist
     * überlebt auch einen abgestürzten Tick.
     *
     * @return bool false, wenn die Versuche aufgebraucht sind.
     */
    public function scheduleRetry(int $suggestedDelay = 0): bool
    {
        $this->retries++;
        if ($this->retries > self::MAX_RETRIES) {
            return false;
        }

        // 5, 10, 20, 40, 80, 160 Sekunden, plus etwas Streuung gegen Gleichtakt.
        $backoff = min(300, 5 * (2 ** ($this->retries - 1)));
        $delay = max($suggestedDelay, $backoff) + random_int(0, 5);
        $this->retryAfter = time() + $delay;
        $this->save();

        return true;
    }

    public function clearRetries(): void
    {
        if ($this->retries !== 0 || $this->retryAfter !== 0) {
            $this->retries = 0;
            $this->retryAfter = 0;
        }
    }

    /**
     * Halbiert die Abschnittsgrösse, gerundet auf ein Vielfaches von 256 KB.
     *
     * Manche Server und Firewalls brechen bei grossen Rümpfen ab. Einmal verkleinert
     * bleibt der Wert klein, ein Wieder-Hochregeln würde nur erneut ins Messer laufen.
     *
     * @return bool false, wenn die Mindestgrösse bereits erreicht ist.
     */
    public function reduceChunkSize(): bool
    {
        if ($this->chunkSize <= self::MIN_CHUNK_SIZE) {
            return false;
        }

        $halved = intdiv($this->chunkSize, 2);
        $aligned = intdiv($halved, self::MIN_CHUNK_SIZE) * self::MIN_CHUNK_SIZE;
        $this->chunkSize = max(self::MIN_CHUNK_SIZE, $aligned);

        return true;
    }

    /**
     * Kennzeichen des aktuellen Fortschritts, um Bewegung von Stillstand zu unterscheiden.
     *
     * Ändert sich der Wert zwischen zwei Prüfungen, kommt der Lauf voran und hängt nur an
     * einem lahmen Loopback. Bleibt er gleich, steht er wirklich. Ohne diese Unterscheidung
     * gäbe der Wächter bei einem langen, aber gesunden Lauf nach wenigen Runden auf.
     */
    public function progressMark(): string
    {
        return implode('|', [
            $this->phase,
            (string) $this->offset,
            (string) ($this->exportCursor['phase'] ?? ''),
            (string) ($this->exportCursor['table_index'] ?? 0),
            (string) ($this->exportCursor['row_offset'] ?? 0),
            (string) ($this->exportCursor['uploads_file_index'] ?? 0),
        ]);
    }

    public function progressPercent(): int
    {
        if ($this->totalSize <= 0) {
            return 0;
        }

        return (int) min(100, floor($this->offset / $this->totalSize * 100));
    }

    /**
     * Alles, was die Anzeige über den Stand wissen muss.
     *
     * Ein reiner Prozentwert reicht nicht: die Grösse des Archivs steht erst nach dem
     * Export fest, vorher lässt sich der Anteil gar nicht ausrechnen. Ein Balken auf null
     * über mehrere Minuten sieht dann aus wie ein Stillstand, obwohl gearbeitet wird.
     * Darum gibt es hier auch "unbestimmt" als gültige Antwort, samt Angabe, woran der
     * Lauf gerade sitzt.
     *
     * @return array{percent: int|null, phase: string, label: string, detail: string, stale: bool, waiting: bool}
     */
    public function progressInfo(): array
    {
        $percent = match ($this->phase) {
            self::PHASE_EXPORT => null,
            self::PHASE_WRITE => $this->totalSize > 0 ? $this->progressPercent() : null,
            self::PHASE_SESSION => 0,
            self::PHASE_UPLOAD => $this->totalSize > 0 ? $this->progressPercent() : null,
            self::PHASE_VERIFY, self::PHASE_ROTATE => 100,
            default => 100,
        };

        return [
            'percent' => $percent,
            'phase' => $this->phase,
            'label' => $this->phaseLabel(),
            'detail' => $this->phaseDetail(),
            'stale' => $this->isStale(),
            'waiting' => $this->isWaiting(),
        ];
    }

    public function phaseLabel(): string
    {
        return match ($this->phase) {
            self::PHASE_EXPORT => __('Backup wird erstellt', 'rh-backup'),
            self::PHASE_WRITE => __('Backup wird geschrieben', 'rh-backup'),
            self::PHASE_SESSION => __('Übertragung wird vorbereitet', 'rh-backup'),
            self::PHASE_UPLOAD => __('Wird übertragen', 'rh-backup'),
            self::PHASE_VERIFY => __('Wird geprüft', 'rh-backup'),
            self::PHASE_ROTATE => __('Ältere Kopien werden aufgeräumt', 'rh-backup'),
            self::PHASE_DONE => __('Fertig', 'rh-backup'),
            self::PHASE_FAILED => __('Fehlgeschlagen', 'rh-backup'),
            default => $this->phase,
        };
    }

    /**
     * Woran der Lauf gerade sitzt. Während des Exports ist das der einzige sichtbare
     * Beweis dafür, dass sich überhaupt etwas bewegt.
     */
    private function phaseDetail(): string
    {
        if ($this->phase === self::PHASE_EXPORT) {
            $stufe = (string) ($this->exportCursor['phase'] ?? '');

            if ($stufe === 'zip_uploads') {
                return sprintf(
                    /* translators: %d: number of files */
                    __('Mediathek, %d Dateien verpackt', 'rh-backup'),
                    (int) ($this->exportCursor['uploads_file_index'] ?? 0)
                );
            }

            if ($stufe === 'sql' || $stufe === '') {
                return sprintf(
                    /* translators: %d: number of tables */
                    __('Datenbank, %d Tabellen gesichert', 'rh-backup'),
                    (int) ($this->exportCursor['table_index'] ?? 0)
                );
            }

            return __('Archiv wird gepackt', 'rh-backup');
        }

        if ($this->phase === self::PHASE_UPLOAD && $this->totalSize > 0) {
            return sprintf(
                /* translators: %1$s: transferred, %2$s: total */
                __('%1$s von %2$s', 'rh-backup'),
                size_format($this->offset),
                size_format($this->totalSize)
            );
        }

        return '';
    }

    public function duration(): int
    {
        return ($this->endedAt ?? time()) - $this->createdAt;
    }

    /**
     * Zeitbudget eines Ticks, abgeleitet aus dem Limit des Servers.
     *
     * Ein fester Wert wäre entweder auf schwachen Servern zu gross (der Prozess stirbt
     * mitten im Abschnitt) oder auf starken zu klein (unnötig viele Runden).
     */
    private static function budgetFromServerLimits(): float
    {
        $limit = (int) ini_get('max_execution_time');

        // 0 heisst unbegrenzt, das gilt aber nur für PHP selbst: der Webserver hat
        // meist trotzdem eine eigene Grenze. Darum konservativ deckeln.
        if ($limit <= 0) {
            return 20.0;
        }

        return (float) max(5, min(20, (int) floor($limit * 0.5)));
    }

    /**
     * Startgrösse eines Abschnitts, abgeleitet aus dem Speicherlimit des Servers.
     *
     * Gemessen: ein Abschnitt von acht Megabyte belegt rund dreiundzwanzig Megabyte
     * Arbeitsspeicher, weil er beim Lesen zusammengesetzt und danach für die Übertragung
     * noch einmal kopiert wird. Ein fester Wert von acht Megabyte ist deshalb auf einem
     * Server mit vierundsechzig Megabyte Limit neben WordPress selbst zu knapp. Die
     * Verkleinerung nach einem Fehlversuch gibt es schon, nur der Startwert war blind.
     */
    private static function chunkFromServerLimits(): int
    {
        $limit = self::memoryLimitBytes();

        // Kein Limit: das grosszügige Mass, wie bisher.
        if ($limit <= 0) {
            return self::CHUNK_SIZE;
        }

        // Ein Abschnitt kostet gemessen etwa das Dreifache seiner Grösse an Speicher.
        // Ein Zwölftel des Limits landet damit bei rund einem Viertel Spitzenlast, der
        // Rest bleibt für WordPress selbst, das im Backend schnell dreissig Megabyte
        // belegt. Bei einem Limit von vierundsechzig Megabyte sind das gut fünf
        // Megabyte pro Abschnitt statt acht.
        $abschnitt = intdiv($limit, 12);
        $gerundet = intdiv($abschnitt, self::MIN_CHUNK_SIZE) * self::MIN_CHUNK_SIZE;

        return max(self::MIN_CHUNK_SIZE, min(self::CHUNK_SIZE, $gerundet));
    }

    /**
     * Das Speicherlimit in Bytes, oder 0 wenn keines gesetzt ist.
     */
    private static function memoryLimitBytes(): int
    {
        $roh = trim((string) ini_get('memory_limit'));

        if ($roh === '' || $roh === '-1') {
            return 0;
        }

        $einheit = strtolower(substr($roh, -1));
        $zahl = (int) $roh;

        return match ($einheit) {
            'g' => $zahl * 1024 * 1024 * 1024,
            'm' => $zahl * 1024 * 1024,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'spawn_token' => $this->spawnToken,
            'phase' => $this->phase,
            'trigger' => $this->trigger,
            'created_at' => $this->createdAt,
            'last_update_at' => $this->lastUpdateAt,
            'ended_at' => $this->endedAt,
            'tick_budget' => $this->tickBudget,
            'export_cursor' => $this->exportCursor,
            'zip_path' => $this->zipPath,
            'file_name' => $this->fileName,
            'total_size' => $this->totalSize,
            'session_uri' => $this->sessionUri,
            'offset' => $this->offset,
            'chunk_size' => $this->chunkSize,
            'retries' => $this->retries,
            'retry_after' => $this->retryAfter,
            'file_id' => $this->fileId,
            'message' => $this->message,
            'error' => $this->error,
            'deleted_copies' => $this->deletedCopies,
            'failed_phase' => $this->failedPhase,
            'mode' => $this->mode,
            'scope' => $this->scope,
            'archive_state' => $this->archiveState,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['job_id'] ?? ''),
            spawnToken: (string) ($data['spawn_token'] ?? ''),
            phase: (string) ($data['phase'] ?? self::PHASE_EXPORT),
            trigger: (string) ($data['trigger'] ?? 'manual'),
            createdAt: (int) ($data['created_at'] ?? time()),
            lastUpdateAt: (int) ($data['last_update_at'] ?? time()),
            endedAt: isset($data['ended_at']) ? (int) $data['ended_at'] : null,
            tickBudget: (float) ($data['tick_budget'] ?? 20.0),
            exportCursor: is_array($data['export_cursor'] ?? null) ? $data['export_cursor'] : [],
            zipPath: (string) ($data['zip_path'] ?? ''),
            fileName: (string) ($data['file_name'] ?? ''),
            totalSize: (int) ($data['total_size'] ?? 0),
            sessionUri: (string) ($data['session_uri'] ?? ''),
            offset: (int) ($data['offset'] ?? 0),
            chunkSize: (int) ($data['chunk_size'] ?? self::CHUNK_SIZE),
            retries: (int) ($data['retries'] ?? 0),
            retryAfter: (int) ($data['retry_after'] ?? 0),
            fileId: (string) ($data['file_id'] ?? ''),
            message: (string) ($data['message'] ?? ''),
            error: (string) ($data['error'] ?? ''),
            deletedCopies: (int) ($data['deleted_copies'] ?? 0),
            failedPhase: (string) ($data['failed_phase'] ?? ''),
            mode: (string) ($data['mode'] ?? Settings::MODE_DRIVE),
            scope: (string) ($data['scope'] ?? Settings::SCOPE_UPLOADS),
            archiveState: is_array($data['archive_state'] ?? null) ? $data['archive_state'] : [],
        );
    }
}
