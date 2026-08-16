<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

use RhBlueprint\Core\Mail\Mail;
use RhBlueprint\Core\Mail\MailMessage;

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

        $subject = __('Sicherung nach Google Drive fehlgeschlagen', 'rh-backup');

        $message = new MailMessage(__('Sicherung fehlgeschlagen', 'rh-backup'), $site);
        $message->kind(ReportContribution::KIND_FAILURE);

        $message->status(
            MailMessage::TONE_ALERT,
            __('Die automatische Sicherung nach Google Drive ist nicht durchgelaufen.', 'rh-backup')
        );

        $rows = [
            __('Grund', 'rh-backup') => $job->error !== '' ? $job->error : __('unbekannt', 'rh-backup'),
            __('Schritt', 'rh-backup') => $this->phaseLabel($job->failedPhase !== '' ? $job->failedPhase : $job->phase),
            __('Zeitpunkt', 'rh-backup') => wp_date('d.m.Y H:i'),
        ];

        if ($job->totalSize > 0) {
            $rows[__('Fortschritt', 'rh-backup')] = sprintf(
                /* translators: %1$s: transferred, %2$s: total */
                __('%1$s von %2$s übertragen', 'rh-backup'),
                size_format($job->offset),
                size_format($job->totalSize)
            );
        }

        $message->rows($rows);
        $message->text(__('Die vorhandenen Sicherungen in Google Drive sind unverändert, es wurde nichts gelöscht.', 'rh-backup'));
        $message->button(__('Sicherung erneut versuchen', 'rh-backup'), admin_url('admin.php?page=rh-blueprint&tab=backup'));

        Mail::send($recipient, $subject, $message, $this->footerNote($site));
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

        $subject = __('Die automatische Sicherung läuft nicht', 'rh-backup');

        $message = new MailMessage(__('Automatische Sicherung läuft nicht', 'rh-backup'), $site);
        $message->kind(ReportContribution::KIND_CRON);

        $message->status(
            MailMessage::TONE_ALERT,
            __('Seit längerem hat keine automatische Sicherung mehr stattgefunden.', 'rh-backup')
        );

        $message->section(__('Was auffällt', 'rh-backup'));
        $message->bullets(array_map(
            static fn (string $problem): array => ['text' => $problem, 'tone' => MailMessage::TONE_WARN],
            $problems
        ));

        if ($pingUrl !== '') {
            $message->section(__('Abhilfe', 'rh-backup'));
            $message->text(__('Diesen Aufruf einmal täglich vom Server oder einem Uptime-Dienst ausführen lassen:', 'rh-backup'));
            $message->code('curl -s "' . $pingUrl . '"');
        }

        $message->button(__('Zeitsteuerung ansehen', 'rh-backup'), admin_url('admin.php?page=rh-blueprint&tab=backup'));

        Mail::send($recipient, $subject, $message, $this->footerNote($site));
    }

    private function footerNote(string $site): string
    {
        return sprintf(
            /* translators: %s: site domain */
            __('Automatische Nachricht von %s, verschickt vom Backup-Modul der Website.', 'rh-backup'),
            $site
        );
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
