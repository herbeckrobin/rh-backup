<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Meldet einen gescheiterten Lauf per E-Mail.
 *
 * Der schlimmste Fehlermodus eines Offsite-Backups ist das stille Scheitern: der Kunde
 * glaubt monatelang, er sei gesichert, und merkt es erst im Ernstfall. Darum geht jeder
 * Fehlschlag raus, auch wenn niemand hinschaut.
 *
 * Der Versand läuft über wp_mail und damit automatisch über rh-smtp, wenn das Modul
 * installiert ist.
 */
final class Notifier
{
    public function reportFailure(UploadJob $job): void
    {
        $recipient = Settings::notifyEmail();

        // Fehler immer protokollieren, auch wenn keine Adresse hinterlegt ist.
        $this->log(sprintf('Lauf fehlgeschlagen in Phase "%s": %s', $job->failedPhase !== '' ? $job->failedPhase : $job->phase, $job->error));

        update_option(Settings::OPTION_LAST_RUN, [
            'time' => time(),
            'success' => false,
            'duration' => $job->duration(),
            'size' => $job->totalSize,
            'file' => $job->fileName,
            'deleted' => 0,
            'trigger' => $job->trigger,
            'message' => $job->error,
        ], false);

        if ($recipient === '' || ! is_email($recipient)) {
            return;
        }

        $site = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);

        $subject = sprintf(
            /* translators: %s: site domain */
            __('[%s] Sicherung nach Google Drive fehlgeschlagen', 'rh-backup'),
            $site
        );

        $lines = [
            sprintf(
                /* translators: %s: site domain */
                __('Die automatische Sicherung der Website %s nach Google Drive ist fehlgeschlagen.', 'rh-backup'),
                $site
            ),
            '',
            sprintf(
                /* translators: %s: error message */
                __('Grund: %s', 'rh-backup'),
                $job->error !== '' ? $job->error : __('unbekannt', 'rh-backup')
            ),
            sprintf(
                /* translators: %s: phase name */
                __('Schritt: %s', 'rh-backup'),
                $this->phaseLabel($job->failedPhase !== '' ? $job->failedPhase : $job->phase)
            ),
            sprintf(
                /* translators: %s: formatted date */
                __('Zeitpunkt: %s', 'rh-backup'),
                wp_date('d.m.Y H:i')
            ),
        ];

        if ($job->totalSize > 0) {
            $lines[] = sprintf(
                /* translators: %1$s: transferred, %2$s: total */
                __('Fortschritt: %1$s von %2$s übertragen.', 'rh-backup'),
                size_format($job->offset),
                size_format($job->totalSize)
            );
        }

        $lines[] = '';
        $lines[] = __('Die vorhandenen Sicherungen in Google Drive sind unverändert, es wurde nichts gelöscht.', 'rh-backup');
        $lines[] = '';
        $lines[] = __('Erneut versuchen lässt sich die Sicherung im Adminbereich unter RH Blueprint, Backup.', 'rh-backup');
        $lines[] = admin_url('admin.php?page=rh-blueprint&tab=backup');

        wp_mail($recipient, $subject, implode("\n", $lines));
    }

    /**
     * Meldet, dass die Zeitsteuerung nicht mehr läuft.
     *
     * Anders als ein Fehlschlag hat das keinen Lauf, über den man berichten könnte:
     * es ist ja gar nichts passiert. Genau deshalb braucht es die Mail, sonst merkt es
     * niemand, bis die Sicherung im Ernstfall fehlt.
     *
     * @param array<int, string> $problems
     */
    public function reportCronProblem(array $problems, string $pingUrl = ''): void
    {
        $this->log('Zeitsteuerung auffällig: ' . implode(' | ', $problems));

        $recipient = Settings::notifyEmail();
        if ($recipient === '' || ! is_email($recipient) || $problems === []) {
            return;
        }

        $site = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);

        $subject = sprintf(
            /* translators: %s: site domain */
            __('[%s] Die automatische Sicherung läuft nicht', 'rh-backup'),
            $site
        );

        $lines = [
            sprintf(
                /* translators: %s: site domain */
                __('Bei der Website %s hat seit längerem keine automatische Sicherung mehr stattgefunden.', 'rh-backup'),
                $site
            ),
            '',
        ];

        foreach ($problems as $problem) {
            $lines[] = '- ' . $problem;
        }

        $lines[] = '';

        if ($pingUrl !== '') {
            $lines[] = __('Abhilfe: diesen Aufruf einmal täglich vom Server oder einem Uptime-Dienst ausführen lassen.', 'rh-backup');
            $lines[] = '';
            $lines[] = 'curl -s "' . $pingUrl . '"';
            $lines[] = '';
        }

        $lines[] = __('Der Zustand der Zeitsteuerung steht im Adminbereich unter RH Blueprint, Backup.', 'rh-backup');
        $lines[] = admin_url('admin.php?page=rh-blueprint&tab=backup');

        wp_mail($recipient, $subject, implode("\n", $lines));
    }

    private function phaseLabel(string $phase): string
    {
        return match ($phase) {
            UploadJob::PHASE_EXPORT => __('Backup erstellen', 'rh-backup'),
            UploadJob::PHASE_SESSION => __('Übertragung vorbereiten', 'rh-backup'),
            UploadJob::PHASE_UPLOAD => __('Übertragung', 'rh-backup'),
            UploadJob::PHASE_VERIFY => __('Prüfung', 'rh-backup'),
            UploadJob::PHASE_ROTATE => __('Aufräumen', 'rh-backup'),
            default => $phase,
        };
    }

    private function log(string $message): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnose auf Kundenseiten, sonst nicht nachvollziehbar.
        error_log('[rh-backup] Offsite: ' . $message);
    }
}
