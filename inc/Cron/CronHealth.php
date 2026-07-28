<?php

declare(strict_types=1);

namespace RhBackup\Cron;

use RhBackup\Offsite\Connection;
use RhBackup\Offsite\Notifier;
use RhBackup\Offsite\Scheduler;
use RhBackup\Offsite\Settings;

/**
 * Merkt, wenn die Zeitsteuerung nicht mehr läuft, und sagt Bescheid.
 *
 * Der schlimmste Fehlermodus einer Sicherung ist der stille Ausfall: niemand bekommt
 * eine Fehlermeldung, weil gar nichts erst startet. Der Ping-Endpunkt ist die Abhilfe
 * dafür, aber nur, wenn ihn jemand eingerichtet hat. Diese Prüfung fängt die Fälle ab,
 * in denen das nicht passiert ist.
 *
 * Die Reaktion ist gestaffelt und nicht sofort laut: eine einzelne Feststellung kann
 * an einem verpassten Termin liegen. Erst wenn sich das über Stunden bestätigt, wird
 * es sichtbar, und erst danach geht eine Mail raus.
 */
final class CronHealth
{
    public const OPTION_STATE = 'rhbackup_cron_health';

    /** Häufiger als das wird nicht geprüft, sonst läuft es bei jedem Klick im Backend. */
    private const CHECK_INTERVAL = HOUR_IN_SECONDS;

    /** Ab dieser Anzahl bestätigter Feststellungen wird die Warnung im Backend sichtbar. */
    private const STRIKES_NOTICE = 2;

    /** Ab dieser Anzahl geht eine Mail raus. */
    private const STRIKES_MAIL = 3;

    /** So lange darf ein Termin überfällig sein, bevor es als Ausfall gilt. */
    private const OVERDUE_TOLERANCE = HOUR_IN_SECONDS;

    /** Ohne WP-Cron muss innerhalb dieser Frist ein Ping angekommen sein. */
    private const PING_TOLERANCE = 2 * DAY_IN_SECONDS;

    public function __construct(private readonly Notifier $notifier)
    {
    }

    public function boot(): void
    {
        add_action('admin_init', [$this, 'maybeCheck']);
        add_action('admin_notices', [$this, 'maybeNotice']);
    }

    public function maybeCheck(): void
    {
        $state = self::state();

        if (time() - (int) $state['checked_at'] < self::CHECK_INTERVAL) {
            return;
        }

        // Ohne Verbindung gibt es keinen Zeitplan, den man überwachen könnte.
        if (! (new Connection())->isConnected()) {
            self::saveState(['checked_at' => time(), 'strikes' => 0, 'notified' => false, 'problems' => []]);

            return;
        }

        $problems = self::detect();
        $strikes = $problems === [] ? 0 : (int) $state['strikes'] + 1;
        $notified = (bool) $state['notified'];

        if ($problems === []) {
            $notified = false;
        } elseif ($strikes >= self::STRIKES_MAIL && ! $notified) {
            $this->notifier->reportCronProblem($problems, PingEndpoint::url());
            $notified = true;
        }

        self::saveState([
            'checked_at' => time(),
            'strikes' => $strikes,
            'notified' => $notified,
            'problems' => $problems,
        ]);
    }

    /**
     * Die zuletzt festgestellten Probleme, für die Anzeige im Backup-Bereich.
     *
     * @return array<int, string>
     */
    public static function problems(): array
    {
        $state = self::state();
        $problems = $state['problems'];

        return is_array($problems) ? array_map('strval', $problems) : [];
    }

    public function maybeNotice(): void
    {
        $state = self::state();

        if ((int) $state['strikes'] < self::STRIKES_NOTICE || $state['problems'] === []) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        $url = PingEndpoint::url();

        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('RH Backup: die automatische Sicherung läuft möglicherweise nicht.', 'rh-backup')
            . '</strong></p><ul style="list-style:disc;padding-left:20px;margin:0 0 8px">';

        foreach (self::problems() as $problem) {
            echo '<li>' . esc_html($problem) . '</li>';
        }

        echo '</ul><p>';
        if ($url !== '') {
            echo esc_html__('Abhilfe: diesen Aufruf täglich vom Server oder einem Uptime-Dienst ausführen lassen.', 'rh-backup')
                . '<br><code>curl -s ' . esc_html($url) . '</code>';
        }
        echo '</p></div>';
    }

    /**
     * Was gerade nicht stimmt. Leer heisst: alles in Ordnung.
     *
     * @return array<int, string>
     */
    public static function detect(): array
    {
        $problems = [];
        $ping = PingEndpoint::lastPing();

        $overdue = Scheduler::overdueBy();
        if ($overdue !== null && $overdue > self::OVERDUE_TOLERANCE) {
            $problems[] = sprintf(
                /* translators: %s: human readable time difference */
                __('Der geplante Termin ist seit %s überfällig. WordPress führt seine Zeitsteuerung offenbar nicht mehr aus.', 'rh-backup'),
                human_time_diff(time() - $overdue)
            );
        }

        if (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') === true) {
            if ($ping === null || time() - $ping > self::PING_TOLERANCE) {
                $problems[] = __('Die Zeitsteuerung von WordPress ist abgeschaltet (DISABLE_WP_CRON) und es kam kein Aufruf von aussen an.', 'rh-backup');
            }
        }

        $last = get_option(Settings::OPTION_LAST_RUN);
        $grenze = 2 * Scheduler::intervalSeconds();
        if (is_array($last) && isset($last['time'])) {
            $alter = time() - (int) $last['time'];
            if ($alter > $grenze) {
                // "vor %s" statt "liegt %s zurück": human_time_diff liefert im Deutschen
                // die Dativform ("3 Monaten"), die nur nach einer Präposition passt.
                $problems[] = sprintf(
                    /* translators: %s: human readable time difference */
                    __('Die letzte Sicherung war vor %s, das ist mehr als das Doppelte des eingestellten Abstands.', 'rh-backup'),
                    human_time_diff(time() - $alter)
                );
            }
        }

        return $problems;
    }

    /**
     * @return array{checked_at: int, strikes: int, notified: bool, problems: array<int, string>}
     */
    private static function state(): array
    {
        $raw = get_option(self::OPTION_STATE, []);
        $raw = is_array($raw) ? $raw : [];

        return [
            'checked_at' => (int) ($raw['checked_at'] ?? 0),
            'strikes' => (int) ($raw['strikes'] ?? 0),
            'notified' => (bool) ($raw['notified'] ?? false),
            'problems' => is_array($raw['problems'] ?? null) ? array_map('strval', $raw['problems']) : [],
        ];
    }

    /**
     * @param array{checked_at: int, strikes: int, notified: bool, problems: array<int, string>} $state
     */
    private static function saveState(array $state): void
    {
        // Bewusst mitgeladen. Die Prüfung hängt an jedem Aufruf des Backends, weil sie
        // gerade dann greifen muss, wenn der Zeitplan tot ist und niemand mehr etwas
        // anstösst. Nicht mitgeladen wäre das eine eigene Abfrage bei jedem Klick, nur
        // um festzustellen, dass die Stunde noch nicht um ist. Der Zustand sind ein
        // paar Zahlen und höchstens drei kurze Sätze.
        update_option(self::OPTION_STATE, $state, true);
    }
}
