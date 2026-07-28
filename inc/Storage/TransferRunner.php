<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhDbEngine\JobLock;

/**
 * Bewegt Sicherungen von einem Ablageort zum anderen.
 *
 * Gebraucht in beide Richtungen: beim Umschalten des Ablageorts wandern die vorhandenen
 * Sicherungen mit, und für eine Wiederherstellung aus Google Drive muss das Archiv erst
 * hierher kommen.
 *
 * Der Antrieb ist derselbe wie beim Sichern: ein Kettchen aus Loopback-Aufrufen, jeder
 * mit Zeitbudget, dazu ein Wächter über den Cron. Ein Umzug von mehreren Gigabyte passt
 * in keinen Request, und ein Abbruch mittendrin darf nicht bedeuten, dass wieder bei
 * null begonnen wird.
 */
final class TransferRunner
{
    public const TICK_ACTION = 'rhbackup_transfer_tick';
    public const WATCHDOG_HOOK = 'rhbackup_transfer_watchdog';

    /** So oft darf der Wächter wiederbeleben, ohne dass sich etwas bewegt. */
    private const MAX_REVIVALS = 5;

    private const REVIVAL_OPTION = 'rhbackup_transfer_revivals';

    /** Riegel gegen zwei gleichzeitige Antriebe, siehe UploadRunner. */
    private const TICK_LOCK = 'transfer_tick';

    private const TICK_LOCK_TTL = 240;

    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly RestoreRunner $restore,
    ) {
    }

    public function boot(): void
    {
        add_action('wp_ajax_' . self::TICK_ACTION, [$this, 'handleTickRequest']);
        add_action('wp_ajax_nopriv_' . self::TICK_ACTION, [$this, 'handleTickRequest']);
        add_action(self::WATCHDOG_HOOK, [$this, 'runWatchdog']);
    }

    /**
     * Startet einen Umzug.
     *
     * @param array<int, BackupEntry> $entries
     * @throws \RuntimeException wenn schon einer läuft oder die Orte nicht taugen.
     */
    public function start(
        string $from,
        string $to,
        array $entries,
        bool $deleteAfter,
        string $purpose = TransferJob::PURPOSE_MOVE,
        string $login = ''
    ): TransferJob {
        $laufend = TransferJob::load();
        if ($laufend !== null && ! $laufend->isFinished() && ! $laufend->isStale()) {
            throw new \RuntimeException(__('Es läuft bereits ein Umzug.', 'rh-backup'));
        }

        $quelle = $this->stores->get($from);
        $ziel = $this->stores->get($to);

        if ($quelle === null || $ziel === null) {
            throw new \RuntimeException(__('Der Ablageort ist unbekannt.', 'rh-backup'));
        }

        if (! $ziel->isReady()) {
            throw new \RuntimeException($ziel->notReadyReason());
        }

        if ($entries === []) {
            throw new \RuntimeException(__('Es gibt nichts umzuziehen.', 'rh-backup'));
        }

        delete_option(self::REVIVAL_OPTION);
        $job = TransferJob::create($from, $to, $entries, $deleteAfter, $purpose, $login);

        $this->ensureWatchdog();
        $this->spawnTick($job);

        return $job;
    }

    public function handleTickRequest(): void
    {
        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        $this->runTick($jobId, $token);

        wp_send_json_success();
    }

    /**
     * Ein Arbeitsschritt: ein Stück des aktuellen Archivs übertragen.
     */
    public function runTick(string $jobId, string $token): void
    {
        $job = TransferJob::loadFor($jobId);
        if ($job === null || $job->spawnToken === '' || ! hash_equals($job->spawnToken, $token)) {
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        // Nur ein Antrieb zur Zeit, siehe UploadRunner::runTick.
        if (! JobLock::acquire(self::TICK_LOCK, self::TICK_LOCK_TTL)) {
            return;
        }

        try {
            $this->advance($job);
        } catch (\Throwable $e) {
            $this->abortCurrent($job);
            $job->finishFailure($e->getMessage());

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnose auf Kundenseiten.
            error_log('[rh-backup] Umzug fehlgeschlagen: ' . $e->getMessage());

            return;
        } finally {
            JobLock::release(self::TICK_LOCK);
        }

        if ($job->isFinished()) {
            delete_option(self::REVIVAL_OPTION);

            return;
        }

        $this->spawnTick($job);
    }

    /**
     * Bringt den Umzug um ein Zeitbudget voran.
     */
    private function advance(TransferJob $job): void
    {
        $eintrag = $job->currentEntry();

        if ($eintrag === null) {
            $this->finish($job);

            return;
        }

        $quelle = $this->stores->get($job->fromStore);
        $ziel = $this->stores->get($job->toStore);

        if ($quelle === null || $ziel === null) {
            throw new \RuntimeException(__('Der Ablageort ist unbekannt.', 'rh-backup'));
        }

        $stream = $quelle->open((string) $eintrag['ref']);

        try {
            $ergebnis = $ziel->receive(
                (string) $eintrag['name'],
                $stream,
                $job->offset,
                $job->state,
                $job->tickBudget
            );
        } finally {
            $stream->close();
        }

        $job->offset = (int) $ergebnis['offset'];
        $job->state = (array) $ergebnis['state'];

        // Ausserhalb des Zustands, denn completeCurrent() leert ihn, und der Abschluss
        // braucht den Namen genau danach.
        if ($job->isRestore()) {
            $job->restoreName = (string) $eintrag['name'];
        }

        if (! $ergebnis['done']) {
            $job->message = sprintf(
                /* translators: %1$s: file name, %2$d: current, %3$d: total */
                __('%1$s wird übertragen (%2$d von %3$d).', 'rh-backup'),
                $eintrag['name'],
                $job->doneCount + 1,
                $job->totalCount
            );
            $job->save();

            return;
        }

        // Erst löschen, wenn die Kopie nachweislich angekommen ist. Die Reihenfolge ist
        // nicht verhandelbar: eine Sicherung, die es nur noch einmal gibt, darf nicht
        // verschwinden, weil eine Übertragung halb durchgelaufen ist.
        if ($job->deleteAfter) {
            $quelle->delete((string) $eintrag['ref']);
        }

        $job->completeCurrent();

        if ($job->currentEntry() === null) {
            $this->finish($job);

            return;
        }

        $job->save();
    }

    private function finish(TransferJob $job): void
    {
        // Beim Wiederherstellen ist das Holen nur der halbe Weg. Der Import läuft direkt
        // hinterher, damit der Nutzer nicht ein zweites Mal bestätigen muss, was er schon
        // bestätigt hat.
        if ($job->isRestore()) {
            $this->finishRestore($job);

            return;
        }

        $job->finishSuccess(sprintf(
            /* translators: %1$d: number of files, %2$s: total size */
            _n(
                '%1$d Sicherung umgezogen (%2$s).',
                '%1$d Sicherungen umgezogen (%2$s).',
                $job->doneCount,
                'rh-backup'
            ),
            $job->doneCount,
            size_format($job->doneBytes)
        ));

        $this->unscheduleWatchdog();
    }

    /**
     * Spielt die geholte Sicherung ein und räumt sie danach wieder weg.
     *
     * Die Kopie war nur Mittel zum Zweck: sie stammt aus einem anderen Ablageort und hat
     * hier nichts verloren, sobald sie eingespielt ist.
     */
    private function finishRestore(TransferJob $job): void
    {
        $lokal = $this->stores->local();
        $name = $job->restoreName;
        $eintrag = $name === '' ? null : $this->findByName($lokal, $name);

        if ($eintrag === null) {
            $job->finishFailure(__('Die geholte Sicherung ist nicht auffindbar.', 'rh-backup'));
            $this->unscheduleWatchdog();

            return;
        }

        $pfad = $lokal->path($eintrag->ref);
        if ($pfad === null) {
            $job->finishFailure(__('Die geholte Sicherung ist nicht auffindbar.', 'rh-backup'));
            $this->unscheduleWatchdog();

            return;
        }

        $job->message = __('Sicherung wird eingespielt...', 'rh-backup');
        $job->save();

        $ergebnis = $this->restore->restoreFile($pfad, $job->login);

        $lokal->delete($eintrag->ref);

        $job->state = ['restore_result' => $ergebnis];

        if (in_array($ergebnis, [RestoreRunner::RESULT_NO_SAFETY, RestoreRunner::RESULT_ROLLED_BACK, RestoreRunner::RESULT_ROLLBACK_FAILED], true)) {
            $job->finishFailure(__('Die Wiederherstellung ist fehlgeschlagen. Details stehen im Error-Log.', 'rh-backup'));
        } else {
            $job->finishSuccess(__('Die Sicherung wurde eingespielt.', 'rh-backup'));
        }

        $this->unscheduleWatchdog();
    }

    private function findByName(LocalStore $store, string $name): ?BackupEntry
    {
        foreach ($store->list() as $eintrag) {
            if ($eintrag->name === $name) {
                return $eintrag;
            }
        }

        return null;
    }

    /**
     * Räumt einen halb übertragenen Eintrag auf.
     */
    private function abortCurrent(TransferJob $job): void
    {
        $eintrag = $job->currentEntry();
        $ziel = $this->stores->get($job->toStore);

        if ($eintrag !== null && $ziel !== null) {
            $ziel->abortReceive((string) $eintrag['name'], $job->state);
        }
    }

    private function spawnTick(TransferJob $job): void
    {
        /**
         * Ermöglicht es Tests, die Kette anzuhalten.
         */
        if (apply_filters('rh-backup/transfer/suppress_loopback', false)) {
            return;
        }

        wp_remote_post(admin_url('admin-ajax.php'), [
            'blocking' => false,
            'timeout' => 0.01,
            'cookies' => [],
            // Auf Produktivsystemen wird geprüft, in der Entwicklung nicht: dort sind
            // selbst ausgestellte Zertifikate der Normalfall. Siehe UploadRunner.
            'sslverify' => apply_filters(
                'rh-backup/offsite/loopback_sslverify',
                function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production'
            ),
            'body' => [
                'action' => self::TICK_ACTION,
                'job_id' => $job->jobId,
                'token' => $job->spawnToken,
            ],
        ]);
    }

    /**
     * Belebt einen hängengebliebenen Umzug wieder.
     */
    public function runWatchdog(): void
    {
        $job = TransferJob::load();
        if ($job === null || $job->isFinished()) {
            $this->unscheduleWatchdog();

            return;
        }

        if (! $job->isStale()) {
            return;
        }

        $mark = $job->doneCount . ':' . $job->offset;
        $state = get_option(self::REVIVAL_OPTION, []);
        $revivals = is_array($state) ? (int) ($state['count'] ?? 0) : (int) $state;
        $lastMark = is_array($state) ? (string) ($state['mark'] ?? '') : '';

        // Bewegt sich etwas, ist der Umzug gesund und nur der Loopback fällt aus.
        if ($lastMark !== '' && $mark !== $lastMark) {
            $revivals = 0;
        }

        if ($revivals >= self::MAX_REVIVALS) {
            $this->abortCurrent($job);
            $job->finishFailure(__('Der Umzug ist mehrfach stehengeblieben und wurde abgebrochen.', 'rh-backup'));
            delete_option(self::REVIVAL_OPTION);
            $this->unscheduleWatchdog();

            return;
        }

        update_option(self::REVIVAL_OPTION, ['count' => $revivals + 1, 'mark' => $mark], false);
        $this->runTick($job->jobId, $job->spawnToken);
    }

    private function ensureWatchdog(): void
    {
        if (wp_next_scheduled(self::WATCHDOG_HOOK) === false) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'rhbackup_minute', self::WATCHDOG_HOOK);
        }
    }

    private function unscheduleWatchdog(): void
    {
        $termin = wp_next_scheduled(self::WATCHDOG_HOOK);
        if ($termin !== false) {
            wp_unschedule_event($termin, self::WATCHDOG_HOOK);
        }
    }
}
