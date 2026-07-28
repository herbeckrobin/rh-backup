<?php

declare(strict_types=1);

namespace RhBackup;

use RhBackup\Storage\BackupKind;
use RhDbEngine\Storage;

/**
 * Aufräumen im Hintergrund: Rotation der Backups und Garbage Collection der Job-Ordner.
 *
 * Ohne das wächst `rh-blueprint-data/` unbegrenzt. Mit eingeschlossenen Medien sind das
 * Archive im Gigabyte-Bereich pro Lauf, und eine volle Platte ist bei einem Backup-Plugin
 * besonders unangenehm: danach lässt sich auch kein neues Backup mehr schreiben.
 *
 * Die Garbage Collection der Job-Ordner lief bisher ausschliesslich über rh-sync. Ohne
 * installiertes rh-sync blieben abgebrochene Importe samt entpackter db.sql im Klartext liegen.
 */
final class Maintenance
{
    public const CRON_HOOK = 'rh_backup_maintenance';

    /**
     * Wieviele der alten, flach abgelegten Backups behalten werden. Null heisst: alle.
     *
     * Bewusst aus. Diese Archive stammen aus einer Zeit, in der es überhaupt keine
     * Rotation gab: wer damals zwanzig Sicherungen angelegt hat, hat das in dem Wissen
     * getan, dass sie liegen bleiben. Ein Update darf ihm davon nicht fünfzehn wegnehmen,
     * ohne zu fragen. Wer aufräumen will, tut das in der Übersicht oder setzt den Filter.
     * Was ab jetzt entsteht, liegt in den Unterordnern und wird dort rotiert.
     */
    public const KEEP_BACKUPS = 0;

    /**
     * Dasselbe für den alten Ordner der Sicherungskopien. Neue landen in presync/ und
     * werden dort nach der Grenze des Anlasses rotiert.
     */
    public const KEEP_SAFETY_COPIES = 0;

    /** Verwaiste Job-Ordner ab diesem Alter aufräumen. */
    private const JOB_MAX_AGE = 2 * HOUR_IN_SECONDS;

    public function __construct(private readonly Storage $storage)
    {
    }

    public function boot(): void
    {
        add_action(self::CRON_HOOK, [$this, 'run']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Meldet den Cron-Termin ab. Wird beim Deaktivieren des Plugins aufgerufen.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function run(): void
    {
        // Abgebrochene Import- und Export-Läufe. Ein noch laufender Job hält sein
        // Verzeichnis per Heartbeat frisch (Storage::touchJobWorkdir) und bleibt verschont.
        $this->storage->gcStaleJobs(self::JOB_MAX_AGE);

        // Jeder Anlass für sich: von planmässigen Sicherungen will man viele Stände,
        // von Sicherungskopien vor einem Import nur die letzten paar. Vorher lagen alle
        // im selben Ordner und verdrängten sich gegenseitig.
        foreach (array_keys(BackupKind::all()) as $kind) {
            /**
             * Anzahl Stände je Anlass. 0 schaltet die Rotation für diesen Anlass ab.
             */
            $keep = (int) apply_filters(
                'rh-backup/keep_backups_for_kind',
                BackupKind::defaultKeep($kind),
                $kind
            );

            $this->prune($this->storage->backupsSubPath($kind), $keep, BackupKind::label($kind));
        }

        /**
         * Anzahl Backups, die flach im Backup-Ordner behalten werden. 0 schaltet die
         * Rotation ab. Betrifft nur Archive aus der Zeit vor den Unterordnern.
         */
        $keepBackups = (int) apply_filters('rh-backup/keep_backups', self::KEEP_BACKUPS);
        $this->prune($this->storage->backupsPath(), $keepBackups, 'Backup');

        /**
         * Anzahl Sicherungskopien, die behalten werden. 0 schaltet die Rotation ab.
         * Betrifft den alten auto-backups-Ordner, neue Kopien landen in presync/.
         */
        $keepSafety = (int) apply_filters('rh-backup/keep_safety_copies', self::KEEP_SAFETY_COPIES);
        $this->prune($this->storage->autoBackupsPath(), $keepSafety, 'Sicherungskopie');
    }

    /**
     * Behält die $keep neuesten Archive im Ordner und entfernt den Rest.
     *
     * Jede Löschung wird protokolliert: was verschwindet, muss nachvollziehbar sein.
     */
    private function prune(string $dir, int $keep, string $label): void
    {
        if ($keep < 1) {
            return;
        }

        $files = $this->storage->listBackupsIn($dir);
        if (count($files) <= $keep) {
            return;
        }

        foreach (array_slice($files, $keep) as $file) {
            $path = $this->storage->resolveInside($dir, $file);
            if ($path === null || ! is_file($path)) {
                continue;
            }

            $size = (int) filesize($path);
            wp_delete_file($path);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nachvollziehbarkeit gelöschter Backups.
            error_log(sprintf(
                '[rh-backup] Rotation: %s %s entfernt (%s), es bleiben die %d neuesten.',
                $label,
                $file,
                size_format($size),
                $keep
            ));
        }
    }
}
