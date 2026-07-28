<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhBackup\Offsite\Connection;
use RhBackup\Offsite\GoogleDrive;
use RhDbEngine\Archive\ArchiveStream;

/**
 * Sicherungen in Google Drive.
 *
 * Anders als auf der Platte kostet hier jede Auskunft eine Anfrage über das Netz. Die
 * Liste wird deshalb kurz zwischengespeichert: eine Sekunde Wartezeit bei jedem Aufbau
 * der Seite wäre der Preis dafür, dass sich zwischendurch praktisch nie etwas ändert.
 */
final class DriveStore implements BackupStore
{
    public const ID = 'drive';

    /** So lange gilt die zuletzt geholte Liste. */
    private const LIST_TTL = 120;

    private const LIST_TRANSIENT = 'rhbackup_drive_list';

    public function __construct(
        private readonly GoogleDrive $drive,
        private readonly Connection $connection,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('In Google Drive', 'rh-backup');
    }

    public function isReady(): bool
    {
        return $this->connection->isConnected();
    }

    public function notReadyReason(): string
    {
        return $this->isReady()
            ? ''
            : __('Dafür muss zuerst ein Google-Konto verbunden werden.', 'rh-backup');
    }

    /**
     * @return array<int, BackupEntry>
     */
    public function list(): array
    {
        if (! $this->isReady()) {
            return [];
        }

        $gemerkt = get_transient(self::LIST_TRANSIENT);
        if (is_array($gemerkt)) {
            return array_map(
                static fn (array $e): BackupEntry => new BackupEntry(
                    ref: (string) $e['ref'],
                    name: (string) $e['name'],
                    size: (int) $e['size'],
                    time: (int) $e['time'],
                    kind: (string) $e['kind'],
                ),
                $gemerkt
            );
        }

        $eintraege = [];
        $roh = [];

        foreach ($this->drive->listBackups($this->drive->ensureFolder()) as $datei) {
            $name = (string) ($datei['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $eintrag = new BackupEntry(
                ref: (string) ($datei['id'] ?? ''),
                name: $name,
                size: (int) ($datei['size'] ?? 0),
                time: strtotime((string) ($datei['created'] ?? '')) ?: 0,
                kind: $this->kindFromName($name),
            );

            $eintraege[] = $eintrag;
            $roh[] = [
                'ref' => $eintrag->ref,
                'name' => $eintrag->name,
                'size' => $eintrag->size,
                'time' => $eintrag->time,
                'kind' => $eintrag->kind,
            ];
        }

        set_transient(self::LIST_TRANSIENT, $roh, self::LIST_TTL);

        return $eintraege;
    }

    /**
     * Wirft die gemerkte Liste weg. Nach jeder Änderung nötig, sonst zeigt die
     * Oberfläche zwei Minuten lang einen Stand, den es nicht mehr gibt.
     */
    public static function forgetList(): void
    {
        delete_transient(self::LIST_TRANSIENT);
    }

    public function find(string $ref): ?BackupEntry
    {
        foreach ($this->list() as $eintrag) {
            if ($eintrag->ref === $ref) {
                return $eintrag;
            }
        }

        return null;
    }

    public function delete(string $ref): bool
    {
        $ok = $this->drive->deleteFile($ref);
        self::forgetList();

        return $ok;
    }

    public function open(string $ref): ArchiveStream
    {
        $eintrag = $this->find($ref);
        if ($eintrag === null) {
            throw new \RuntimeException(__('Diese Sicherung liegt nicht mehr in Google Drive.', 'rh-backup'));
        }

        return new DriveArchiveStream($this->drive, $ref, $eintrag->size);
    }

    /**
     * Nimmt eine Sicherung auf, abschnittsweise über eine fortsetzbare Google-Sitzung.
     *
     * Die Adresse der Sitzung steht im Zustand: sie gilt eine Woche, und ohne sie müsste
     * ein unterbrochener Umzug von vorn beginnen.
     *
     * @param array<string, mixed> $state
     * @return array{offset: int, done: bool, state: array<string, mixed>}
     */
    public function receive(string $name, ArchiveStream $source, int $offset, array $state, float $budget): array
    {
        $gesamt = $source->size();
        $sitzung = (string) ($state['session'] ?? '');

        if ($sitzung === '') {
            $sitzung = $this->drive->startUploadSession($name, $gesamt, $this->drive->ensureFolder());
            $state['session'] = $sitzung;
            $offset = 0;
        }

        $deadline = microtime(true) + max(0.1, $budget);
        $chunk = (int) ($state['chunk'] ?? 8 * 1024 * 1024);

        while ($offset < $gesamt && microtime(true) < $deadline) {
            $daten = $source->readAt($offset, $chunk);
            if ($daten === '') {
                throw new \RuntimeException(__('Die Sicherung liefert keine Daten mehr, obwohl sie noch nicht vollständig ist.', 'rh-backup'));
            }

            $ergebnis = $this->drive->uploadChunk($sitzung, $daten, $offset, $gesamt);

            $offset = $ergebnis['done']
                ? $gesamt
                : max($offset + strlen($daten), (int) $ergebnis['next_offset']);

            if ($ergebnis['done']) {
                self::forgetList();

                return ['offset' => $offset, 'done' => true, 'state' => $state];
            }
        }

        $state['chunk'] = $chunk;

        return ['offset' => $offset, 'done' => false, 'state' => $state];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function abortReceive(string $name, array $state): void
    {
        // Eine angefangene Sitzung bei Google verfällt von selbst nach einer Woche, und
        // solange sie nicht abgeschlossen ist, entsteht keine sichtbare Datei. Es bleibt
        // also nichts liegen, worum man sich kümmern müsste.
    }

    /**
     * Zu welchem Anlass gehört eine Datei, gemessen an ihrem Namen?
     *
     * In Drive gibt es keine Unterordner nach Anlass, der Name ist die einzige Quelle.
     * Ältere Sicherungen tragen ihn nicht und bleiben ohne Zuordnung.
     */
    private function kindFromName(string $name): string
    {
        foreach (array_keys(BackupKind::all()) as $kind) {
            if (str_contains($name, '-' . $kind . '-')) {
                return $kind;
            }
        }

        return BackupKind::LEGACY;
    }
}
