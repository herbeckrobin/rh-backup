<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

use RhDbEngine\JobLock;

/**
 * Treibt einen Offsite-Lauf über viele Einzel-Requests hinweg voran.
 *
 * Ein Backup von mehreren Gigabyte passt in keinen einzelnen PHP-Request. Der Lauf wird
 * deshalb in Ticks zerlegt: jeder Tick arbeitet ein Zeitbudget ab, sichert seinen Stand
 * und stösst den nächsten an. Angetrieben wird das durch einen nicht blockierenden
 * Loopback-Aufruf auf die eigene Site, abgesichert durch einen Cron-Wächter, der einen
 * hängengebliebenen Lauf wiederbelebt.
 *
 * Der Tick-Endpunkt läuft ohne angemeldeten Benutzer, denn der Loopback schickt keine
 * Cookies mit. Ausgewiesen wird er über ein Einmal-Merkmal, das nur im Job-Zustand in der
 * Datenbank steht und in konstanter Zeit verglichen wird.
 */
final class UploadRunner
{
    public const TICK_ACTION = 'rhbackup_offsite_tick';
    public const WATCHDOG_HOOK = 'rhbackup_offsite_watchdog';

    /** So oft darf der Wächter wiederbeleben, ohne dass sich etwas bewegt hat. */
    private const MAX_REVIVALS = 5;

    /** Riegel, der verhindert, dass zwei Antriebe gleichzeitig am selben Lauf arbeiten. */
    private const TICK_LOCK = 'offsite_tick';

    /**
     * Verfallszeit des Riegels. Grosszügiger als der längste denkbare Tick (ein einzelner
     * Abschnitt darf 120 Sekunden auf Google warten), aber kurz genug, dass ein wirklich
     * gestorbener Prozess den Lauf nicht dauerhaft blockiert.
     */
    private const TICK_LOCK_TTL = 240;

    private const REVIVAL_OPTION = 'rhbackup_offsite_revivals';

    public function __construct(
        private readonly UploadAdvancer $advancer,
        private readonly Notifier $notifier,
    ) {
    }

    public function boot(): void
    {
        add_action('wp_ajax_' . self::TICK_ACTION, [$this, 'handleTickRequest']);
        add_action('wp_ajax_nopriv_' . self::TICK_ACTION, [$this, 'handleTickRequest']);
        add_action(self::WATCHDOG_HOOK, [$this, 'runWatchdog']);
    }

    /**
     * Startet einen neuen Lauf, wenn gerade keiner läuft.
     *
     * @param string $trigger 'manual' oder 'schedule'
     * @throws \RuntimeException wenn bereits ein Lauf aktiv ist oder Voraussetzungen fehlen.
     */
    public function start(string $trigger, string $scope = ''): UploadJob
    {
        $existing = UploadJob::load();
        if ($existing !== null && ! $existing->isFinished() && ! $existing->isStale()) {
            throw new \RuntimeException(__('Es läuft bereits eine Sicherung nach Google Drive.', 'rh-backup'));
        }

        // Im lokalen Modus entsteht nur ein Archiv auf diesem Server. Google spielt dabei
        // keine Rolle, und ein fehlender Zugang darf den Lauf nicht verhindern.
        if (Settings::mode() === Settings::MODE_DRIVE && ! Settings::hasOAuthClient()) {
            throw new \RuntimeException(__('Die Zugangsdaten der Anwendung fehlen. Bitte Client-ID und Secret eintragen.', 'rh-backup'));
        }

        // Läuft gerade ein Export oder eine Wiederherstellung, wäre die Kopie ein
        // Abbild eines halb ersetzten Zustands. Lieber gar nicht starten: der nächste
        // planmässige Termin greift ohnehin.
        if (\RhDbEngine\JobLock::heldUntil('db') !== null) {
            throw new \RuntimeException(__('Gerade läuft ein Backup- oder Wiederherstellungs-Vorgang. Die Sicherung nach Google Drive startet später.', 'rh-backup'));
        }

        delete_option(self::REVIVAL_OPTION);

        $job = UploadJob::create($trigger);

        // Für einen einzelnen Lauf darf der Umfang abweichen, ohne die Einstellung zu
        // ändern: wer einmal die komplette Website sichern will, soll danach nicht daran
        // denken müssen, wieder zurückzustellen.
        if ($scope !== '' && isset(Settings::scopes()[$scope])) {
            $job->scope = $scope;
            $job->save();
        }

        $this->spawnTick($job);

        return $job;
    }

    /**
     * Nimmt einen Tick entgegen. Antwortet immer freundlich, damit der Endpunkt keine
     * Auskunft darüber gibt, ob eine Kennung existiert.
     */
    public function handleTickRequest(): void
    {
        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        $this->runTick($jobId, $token);

        wp_send_json_success();
    }

    /**
     * Ein Arbeitsschritt: prüfen, vorantreiben, nächsten anstossen.
     */
    public function runTick(string $jobId, string $token): void
    {
        $job = UploadJob::loadFor($jobId);
        if ($job === null || $job->spawnToken === '' || ! hash_equals($job->spawnToken, $token)) {
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        // Rückzugsfrist nach einem Fehlversuch: nur weiterreichen, nicht arbeiten.
        if ($job->isWaiting()) {
            $job->save();
            $this->spawnTick($job);

            return;
        }

        // Es gibt drei Antriebe für einen Tick: den Loopback, den Cron-Wächter und den
        // Aufruf von aussen. Treffen zwei davon zusammen, würden beide am selben Archiv
        // arbeiten, und der Lauf stirbt an einer halb geschriebenen Datei. Wer den Riegel
        // nicht bekommt, geht ohne Aufhebens: der andere ist ja gerade dabei.
        if (! JobLock::acquire(self::TICK_LOCK, self::TICK_LOCK_TTL)) {
            return;
        }

        try {
            $this->advancer->advance($job);
        } catch (\Throwable $e) {
            $job->finishFailure($e->getMessage());
            $this->notifier->reportFailure($job);

            return;
        } finally {
            JobLock::release(self::TICK_LOCK);
        }

        if ($job->isFinished()) {
            if ($job->phase === UploadJob::PHASE_DONE) {
                $this->recordSuccess($job);
            }

            return;
        }

        $this->spawnTick($job);
    }

    /**
     * Soll der Loopback das Zertifikat prüfen?
     *
     * Auf einem Produktivsystem ja: dort ist ein gültiges Zertifikat der Normalfall, und
     * die Prüfung abzuschalten hiesse, sie ohne Not preiszugeben. In der Entwicklung und
     * auf Testsystemen dagegen ist ein selbst ausgestelltes Zertifikat üblich, und eine
     * Prüfung würde die Kette schlicht anhalten. Der Filter bleibt für Sonderfälle.
     */
    private static function loopbackVerifiesCert(): bool
    {
        return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
    }

    /**
     * Stösst den nächsten Tick an, ohne auf ihn zu warten.
     */
    private function spawnTick(UploadJob $job): void
    {
        /**
         * Ermöglicht es Tests, die Kette anzuhalten.
         */
        if (apply_filters('rh-backup/offsite/suppress_loopback', false)) {
            return;
        }

        wp_remote_post(admin_url('admin-ajax.php'), [
            'blocking' => false,
            'timeout' => 0.01,
            'cookies' => [],
            'sslverify' => apply_filters('rh-backup/offsite/loopback_sslverify', self::loopbackVerifiesCert()),
            'body' => [
                'action' => self::TICK_ACTION,
                'job_id' => $job->jobId,
                'token' => $job->spawnToken,
            ],
        ]);
    }

    /**
     * Läuft jede Minute und belebt einen hängengebliebenen Lauf wieder.
     *
     * Der Loopback kann ausfallen, etwa wenn die Site sich selbst nicht erreicht. Dann
     * ist der Cron die einzige Instanz, die den Lauf noch bewegt, und der Tick läuft
     * direkt in diesem Request statt über einen weiteren Loopback.
     */
    public function runWatchdog(): void
    {
        $job = UploadJob::load();
        if ($job === null || $job->isFinished() || ! $job->isStale()) {
            return;
        }

        $mark = $job->progressMark();
        $state = get_option(self::REVIVAL_OPTION, []);
        $revivals = is_array($state) ? (int) ($state['count'] ?? 0) : (int) $state;
        $lastMark = is_array($state) ? (string) ($state['mark'] ?? '') : '';

        // Hat sich der Stand seit der letzten Prüfung bewegt, ist der Lauf gesund und nur
        // der Loopback fällt aus. Dann ist der Wächter der Antrieb, kein Notnagel, und der
        // Zähler beginnt von vorn. Der Deckel soll hängende Läufe fangen, nicht langsame.
        if ($lastMark !== '' && $mark !== $lastMark) {
            $revivals = 0;
        }

        if ($revivals >= self::MAX_REVIVALS) {
            $job->finishFailure(__('Die Sicherung ist mehrfach stehengeblieben und wurde abgebrochen.', 'rh-backup'));
            $this->notifier->reportFailure($job);
            delete_option(self::REVIVAL_OPTION);

            return;
        }

        update_option(self::REVIVAL_OPTION, ['count' => $revivals + 1, 'mark' => $mark], false);
        $this->runTick($job->jobId, $job->spawnToken);
    }

    /**
     * Hält den letzten Lauf für die Anzeige fest.
     */
    private function recordSuccess(UploadJob $job): void
    {
        update_option(Settings::OPTION_LAST_RUN, [
            'time' => time(),
            'success' => true,
            'duration' => $job->duration(),
            'size' => $job->totalSize,
            'file' => $job->fileName,
            'deleted' => $job->deletedCopies,
            'trigger' => $job->trigger,
            'message' => $job->message,
        ], false);

        delete_option(self::REVIVAL_OPTION);
    }
}
