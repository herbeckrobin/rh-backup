<?php

declare(strict_types=1);

namespace RhBackup\Cron;

use RhBackup\Offsite\Scheduler;
use RhBackup\Offsite\UploadJob;
use RhBackup\Offsite\UploadRunner;

/**
 * Von aussen anstossbarer Endpunkt für die planmässige Sicherung.
 *
 * WP-Cron läuft nur, wenn jemand die Seite aufruft. Auf einer Kundenseite mit wenig
 * Verkehr kann deshalb monatelang keine Sicherung stattfinden, ohne dass es auffällt.
 * Ein Aufruf von aussen, etwa durch einen Uptime-Dienst oder einen echten Cron beim
 * Hoster, macht daraus einen verlässlichen Termin.
 *
 * Der Endpunkt übernimmt ausdrücklich nicht die Rolle von WP-Cron für andere Module.
 * Er stösst nur die Sicherung an.
 *
 * Erreichbar als: https://kunde.de/?rhbp_backup_run=<merkmal>
 *
 * Bewusst ein Query-Parameter und kein Pfad: ein Pfad kann mit einer echten Seite
 * kollidieren und hängt an den Permalink-Regeln, was in der Vergangenheit schon
 * Probleme gemacht hat.
 */
final class PingEndpoint
{
    public const PARAM = 'rhbp_backup_run';

    public const OPTION_TOKEN = 'rhbackup_ping_token';
    public const OPTION_LAST_PING = 'rhbackup_ping_last';

    /** Feiner als dieses Raster wird der Zeitpunkt des letzten Aufrufs nicht festgehalten. */
    private const PING_WRITE_INTERVAL = 300;

    public function __construct(private readonly UploadRunner $runner)
    {
    }

    /**
     * Prüft sofort, ob dieser Request ein Ping ist. Kein eigener Hook nötig: der
     * Aufrufer läuft bereits auf init, und früher als der Core kann der Endpunkt
     * ohnehin nicht arbeiten, weil er dessen Einstellungen braucht.
     */
    public function boot(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- öffentlicher Endpunkt, ausgewiesen über das Merkmal.
        $given = isset($_GET[self::PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::PARAM])) : '';
        if ($given === '') {
            // Das Merkmal wird erst gebraucht, wenn es jemand zu sehen bekommt. Es bei
            // jedem Aufruf des Backends nachzuschlagen kostet eine Abfrage, die nirgends
            // hilft: die Option ist bewusst nicht mitgeladen.
            if ($this->isSettingsScreen()) {
                self::ensureToken();
            }

            return;
        }

        $this->handle($given);
    }

    /**
     * Sind wir gerade auf der Einstellungsseite, wo das Merkmal angezeigt wird?
     */
    private function isSettingsScreen(): bool
    {
        if (! is_admin()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Ortsbestimmung, ohne Wirkung.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === \RhBlueprint\Core\Settings\SettingsPage::MENU_SLUG;
    }

    /**
     * Das Merkmal, mit dem sich ein Aufrufer ausweist. Leer heisst: Endpunkt aus.
     */
    public static function token(): string
    {
        $value = get_option(self::OPTION_TOKEN, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Legt das Merkmal an, falls es noch keines gibt, und gibt es zurück.
     */
    public static function ensureToken(): string
    {
        $token = self::token();
        if ($token !== '') {
            return $token;
        }

        return self::regenerateToken();
    }

    public static function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(16));
        update_option(self::OPTION_TOKEN, $token, false);

        return $token;
    }

    public static function url(): string
    {
        $token = self::token();
        if ($token === '') {
            return '';
        }

        return add_query_arg(self::PARAM, $token, home_url('/'));
    }

    /**
     * Zeitpunkt des letzten gültigen Aufrufs, oder null.
     */
    public static function lastPing(): ?int
    {
        $value = (int) get_option(self::OPTION_LAST_PING, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * Hält den Zeitpunkt des Aufrufs fest, aber nicht bei jedem einzelnen.
     *
     * Ein Uptime-Dienst ruft gern im Minutentakt. Jeder Aufruf einen Schreibvorgang in
     * die Datenbank wären knapp anderthalbtausend am Tag, nur um eine Anzeige aktuell zu
     * halten, die niemand minutengenau braucht. Die Selbstprüfung fragt in Stunden, ein
     * Raster von fünf Minuten reicht ihr allemal.
     */
    private function rememberPing(): void
    {
        $letzter = (int) get_option(self::OPTION_LAST_PING, 0);

        if (time() - $letzter < self::PING_WRITE_INTERVAL) {
            return;
        }

        update_option(self::OPTION_LAST_PING, time(), false);
    }

    private function handle(string $given): void
    {
        $token = self::token();

        // Gleiche Antwort für "kein Merkmal gesetzt" und "falsches Merkmal": der
        // Endpunkt soll nicht verraten, ob er überhaupt eingerichtet ist.
        if ($token === '' || ! hash_equals($token, $given)) {
            $this->respond(['status' => 'forbidden'], 403);
        }

        $this->rememberPing();

        $job = UploadJob::load();

        if ($job !== null && ! $job->isFinished()) {
            // Ein Lauf, der sich meldet, wird in Ruhe gelassen. Sonst arbeiteten der
            // Aufruf von aussen und die laufende Tick-Kette gleichzeitig am selben
            // Archiv, und der Lauf stirbt an einer halb geschriebenen Datei. Genau
            // dieselbe Bedingung wie im Cron-Wächter.
            if (! $job->isStale()) {
                $this->respond([
                    'status' => 'running',
                    'phase' => $job->phase,
                    'percent' => $job->progressPercent(),
                ]);
            }

            // Steht er dagegen wirklich still, ist der Aufruf von aussen der Ersatz für
            // den ausgefallenen Loopback.
            $this->runner->runTick($job->jobId, $job->spawnToken);

            $aktuell = UploadJob::load();

            $this->respond([
                'status' => 'ticked',
                'phase' => $aktuell?->phase ?? $job->phase,
                'percent' => $aktuell?->progressPercent() ?? 0,
            ]);
        }

        $faellig = Scheduler::claimDueRun();
        if (! $faellig) {
            $this->respond([
                'status' => 'idle',
                'next_run' => Scheduler::nextRun(),
            ]);
        }

        try {
            $neu = $this->runner->start('schedule');
        } catch (\Throwable $e) {
            $this->respond([
                'status' => 'blocked',
                'message' => $e->getMessage(),
            ], 409);
        }

        $this->respond([
            'status' => 'started',
            'job' => $neu->jobId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $code = 200): never
    {
        if (! headers_sent()) {
            status_header($code);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
        }

        echo wp_json_encode($payload);
        exit;
    }
}
