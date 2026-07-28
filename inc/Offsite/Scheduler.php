<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Plant die wiederkehrende Sicherung und den Wächter über WP-Cron.
 *
 * WordPress kennt von Haus aus nur stündlich, zweimal täglich und täglich. Monatlich,
 * vierteljährlich und das Minuten-Intervall des Wächters kommen hier dazu.
 */
final class Scheduler
{
    public const RUN_HOOK = 'rhbackup_offsite_run';

    /** Eigenes Minuten-Intervall für den Wächter. */
    private const MINUTE_SCHEDULE = 'rhbackup_minute';

    public function __construct(private readonly UploadRunner $runner)
    {
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action(self::RUN_HOOK, [$this, 'runScheduled']);
        add_action('init', [$this, 'ensureScheduled']);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerSchedules(array $schedules): array
    {
        $schedules[self::MINUTE_SCHEDULE] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Jede Minute (RH Backup)', 'rh-backup'),
        ];

        foreach (Settings::intervals() as $key => $definition) {
            if (! isset($schedules[$key])) {
                $schedules[$key] = [
                    'interval' => $definition['interval'],
                    'display' => $definition['label'],
                ];
            }
        }

        return $schedules;
    }

    /**
     * Sorgt dafür, dass die Termine zum eingestellten Zeitplan passen.
     *
     * Der Wächter läuft nur, solange tatsächlich ein Lauf offen ist: ein Minuten-Cron
     * ohne Anlass wäre unnötige Last auf jeder Kundenseite.
     */
    public function ensureScheduled(): void
    {
        $this->syncBackupSchedule();
        $this->syncWatchdog();
    }

    private function syncBackupSchedule(): void
    {
        $scheduled = wp_next_scheduled(self::RUN_HOOK);

        // Im lokalen Modus braucht der Zeitplan kein Google: er erzeugt nur ein Archiv
        // auf diesem Server. Nach Drive geht es nur mit bestehender Verbindung.
        $sinnvoll = Settings::mode() === Settings::MODE_LOCAL || (new Connection())->isConnected();

        if (! $sinnvoll) {
            if ($scheduled !== false) {
                wp_unschedule_event($scheduled, self::RUN_HOOK);
            }

            return;
        }

        $wanted = Settings::interval();
        $current = wp_get_schedule(self::RUN_HOOK);

        if ($current === $wanted) {
            return;
        }

        if ($scheduled !== false) {
            wp_unschedule_event($scheduled, self::RUN_HOOK);
        }

        // Erster Lauf in einer Stunde, nicht sofort: sonst startet das Verbinden
        // unmittelbar einen mehrstündigen Upload.
        wp_schedule_event(time() + HOUR_IN_SECONDS, $wanted, self::RUN_HOOK);
    }

    private function syncWatchdog(): void
    {
        $job = UploadJob::load();
        $needed = $job !== null && ! $job->isFinished();
        $scheduled = wp_next_scheduled(UploadRunner::WATCHDOG_HOOK);

        if ($needed && $scheduled === false) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::MINUTE_SCHEDULE, UploadRunner::WATCHDOG_HOOK);

            return;
        }

        if (! $needed && $scheduled !== false) {
            wp_unschedule_event($scheduled, UploadRunner::WATCHDOG_HOOK);
        }
    }

    /**
     * Der planmässige Lauf.
     */
    public function runScheduled(): void
    {
        try {
            $this->runner->start('schedule');
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnose auf Kundenseiten.
            error_log('[rh-backup] Offsite: geplanter Lauf nicht gestartet: ' . $e->getMessage());
        }
    }

    /**
     * Räumt alle Termine ab. Beim Deaktivieren des Plugins und beim Trennen der Verbindung.
     */
    public static function unscheduleAll(): void
    {
        foreach ([self::RUN_HOOK, UploadRunner::WATCHDOG_HOOK] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp !== false) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    /**
     * Nächster geplanter Lauf als Zeitstempel, oder null.
     */
    public static function nextRun(): ?int
    {
        $timestamp = wp_next_scheduled(self::RUN_HOOK);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Ist der Termin überfällig, wird er hier auf den nächsten geschoben und mit true
     * quittiert. Damit darf der Aufrufer den Lauf starten.
     *
     * Das Weiterschieben gehört zwingend dazu: sonst bliebe der Termin überfällig und
     * jeder weitere Aufruf würde erneut starten. Normalerweise erledigt das WP-Cron
     * selbst, aber genau der läuft in diesem Fall ja nicht.
     */
    public static function claimDueRun(): bool
    {
        $event = wp_get_scheduled_event(self::RUN_HOOK);
        if (! $event || $event->timestamp > time()) {
            return false;
        }

        wp_unschedule_event($event->timestamp, self::RUN_HOOK);

        $interval = isset($event->interval) ? (int) $event->interval : 0;
        $schedule = isset($event->schedule) && is_string($event->schedule) ? $event->schedule : '';

        if ($schedule !== '' && $interval > 0) {
            wp_schedule_event(time() + $interval, $schedule, self::RUN_HOOK);
        }

        return true;
    }

    /**
     * Wie weit ist der geplante Termin überfällig? Null, wenn nichts geplant ist oder
     * der Termin noch in der Zukunft liegt.
     */
    public static function overdueBy(): ?int
    {
        $timestamp = wp_next_scheduled(self::RUN_HOOK);
        if ($timestamp === false) {
            return null;
        }

        $abstand = time() - (int) $timestamp;

        return $abstand > 0 ? $abstand : null;
    }

    /**
     * Länge des eingestellten Zeitplans in Sekunden.
     */
    public static function intervalSeconds(): int
    {
        $intervals = Settings::intervals();

        return (int) ($intervals[Settings::interval()]['interval'] ?? 30 * DAY_IN_SECONDS);
    }
}
