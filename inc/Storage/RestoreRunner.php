<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhDbEngine\Exporter;
use RhDbEngine\Importer;
use RhDbEngine\Storage;

/**
 * Spielt eine Sicherung zurück.
 *
 * Herausgelöst aus der Oberfläche, weil es zwei Wege dorthin gibt: den Klick auf eine
 * lokal liegende Sicherung, und den Abschluss eines Vorgangs, der eine Sicherung erst
 * aus Google Drive holen musste. Beide brauchen dieselbe Sorgfalt, und die soll es nur
 * einmal geben.
 *
 * Die Reihenfolge ist nicht verhandelbar: erst die Sicherungskopie, dann der erste
 * DROP TABLE. Ohne sie hinterlässt jeder Abbruch eine halb ersetzte Datenbank, aus der
 * es keinen Weg zurück gibt.
 */
final class RestoreRunner
{
    public const RESULT_OK = 'ok';
    public const RESULT_INCOMPLETE_UPLOADS = 'incomplete_uploads';
    public const RESULT_LOGGED_OUT = 'logged_out';
    public const RESULT_NO_SAFETY = 'no_safety';
    public const RESULT_ROLLED_BACK = 'rolled_back';
    public const RESULT_ROLLBACK_FAILED = 'rollback_failed';

    private int $uploadsFailed = 0;

    public function __construct(
        private readonly Storage $storage,
        private readonly Exporter $exporter,
        private readonly Importer $importer,
    ) {
    }

    public function boot(): void
    {
        add_action('rh-db-engine/import_incomplete_uploads', [$this, 'noteIncompleteUploads']);
    }

    public function noteIncompleteUploads(int $failed): void
    {
        $this->uploadsFailed = max($this->uploadsFailed, $failed);
    }

    /**
     * Spielt ein Archiv ein, das lokal vorliegt.
     *
     * @param string $login Anmeldename, der danach wieder angemeldet werden soll. Leer,
     *                      wenn niemand angemeldet ist, etwa im Hintergrundvorgang.
     * @return string Eine der RESULT-Konstanten.
     */
    public function restoreFile(string $path, string $login = ''): string
    {
        try {
            $safety = $this->exporter->createBackup(
                false,
                [],
                $this->storage->backupsSubPath(BackupKind::PRESYNC)
            );
        } catch (\Throwable $e) {
            $this->log('Sicherungskopie vor dem Import fehlgeschlagen', $e);

            return self::RESULT_NO_SAFETY;
        }

        try {
            $this->importer->importFromFile($path);
        } catch (\Throwable $e) {
            $this->log('Import fehlgeschlagen, Rückrollen auf die Sicherungskopie', $e);

            return $this->rollback((string) $safety);
        }

        if ($login !== '' && ! $this->restoreSession($login)) {
            return self::RESULT_LOGGED_OUT;
        }

        return $this->uploadsFailed > 0 ? self::RESULT_INCOMPLETE_UPLOADS : self::RESULT_OK;
    }

    /**
     * Ordnet einem Ergebnis die Meldung zu, die die Oberfläche kennt.
     */
    public static function messageFor(string $result): string
    {
        return match ($result) {
            self::RESULT_OK => 'import_ok',
            self::RESULT_INCOMPLETE_UPLOADS => 'import_incomplete_uploads',
            self::RESULT_LOGGED_OUT => 'import_ok_logged_out',
            self::RESULT_NO_SAFETY => 'import_no_safety',
            self::RESULT_ROLLED_BACK => 'import_rolled_back',
            self::RESULT_ROLLBACK_FAILED => 'import_rollback_failed',
            default => 'import_failed',
        };
    }

    private function rollback(string $safetyCopy): string
    {
        try {
            $this->importer->importFromFile($safetyCopy);
        } catch (\Throwable $e) {
            // Schlimmster Fall: Import kaputt UND Rückrollen kaputt. Der Pfad zur
            // Sicherungskopie muss ins Log, sonst ist sie für den Nutzer unauffindbar.
            $this->log('Rückrollen fehlgeschlagen, Sicherungskopie liegt unter ' . $safetyCopy, $e);

            return self::RESULT_ROLLBACK_FAILED;
        }

        return self::RESULT_ROLLED_BACK;
    }

    /**
     * Meldet den Benutzer im wiederhergestellten Stand erneut an.
     *
     * Die Benutzertabelle stammt jetzt aus dem Backup. Existiert das Konto dort unter
     * demselben Namen, bekommt der Browser ein frisches Cookie und die Sitzung läuft
     * weiter. Existiert es nicht, ist der Benutzer ausgesperrt, und das muss er erfahren.
     */
    private function restoreSession(string $login): bool
    {
        $user = get_user_by('login', $login);
        if (! $user instanceof \WP_User) {
            return false;
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        return true;
    }

    private function log(string $context, \Throwable $e): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nachvollziehbarkeit im Ernstfall.
        error_log(sprintf('[rh-backup] %s: %s', $context, $e->getMessage()));

        /**
         * Für Fehler-Tracking, etwa rh-monitor.
         */
        do_action('rh-backup/error', $context, $e);
    }
}
