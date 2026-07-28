<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Zentrale Stelle für alle Einstellungen des Offsite-Backups.
 *
 * Eine Quelle für Schlüssel, Defaults und Wertebereiche. rhbp_setting() kennt die
 * Defaults der Felder nicht (die Option ist leer, solange nie gespeichert wurde),
 * darum laufen alle Zugriffe über diese Klasse statt über verstreute Literale.
 */
final class Settings
{
    public const GROUP = 'offsite';

    /** Wo eine fertige Sicherung dauerhaft liegt. */
    public const MODE = 'mode';
    public const MODE_LOCAL = 'local';
    public const MODE_DRIVE = 'drive';

    /** Was gesichert wird. */
    public const SCOPE = 'scope';
    public const SCOPE_DATABASE = 'database';
    public const SCOPE_UPLOADS = 'uploads';
    public const SCOPE_FULL = 'full';

    /** Nimmt die wp-config.php in ein Voll-Backup auf. Bewusst getrennt, siehe unten. */
    public const INCLUDE_CONFIG = 'include_config';

    /** Einstellungen, die den Betreiber der Website etwas angehen. */
    public const INTERVAL = 'interval';
    public const KEEP_COPIES = 'keep_copies';
    public const FOLDER_NAME = 'folder_name';
    public const NOTIFY_EMAIL = 'notify_email';
    public const INCLUDE_UPLOADS = 'include_uploads';

    /** Zugang des Kunden und Laufzeit-Zustand, jeweils eigene Option mit autoload=no. */
    public const OPTION_REFRESH_TOKEN = 'rhbackup_offsite_refresh_token';
    public const OPTION_ACCOUNT = 'rhbackup_offsite_account';
    public const OPTION_FOLDER_ID = 'rhbackup_offsite_folder_id';
    public const OPTION_LAST_RUN = 'rhbackup_offsite_last_run';

    /** Konstante in der wp-config.php, die Vorrang vor der Datenbank hat. */
    public const CONST_CLIENT_ID = 'RH_BACKUP_GDRIVE_CLIENT_ID';
    public const CONST_CLIENT_SECRET = 'RH_BACKUP_GDRIVE_CLIENT_SECRET';

    public const DEFAULT_KEEP_COPIES = 12;
    public const DEFAULT_FOLDER_NAME = 'Website-Backups';
    public const DEFAULT_INTERVAL = 'rhbackup_monthly';

    /**
     * Auswählbare Zeitpläne. Der Schlüssel ist der WP-Cron-Recurrence-Name.
     *
     * @return array<string, array{label: string, interval: int}>
     */
    public static function intervals(): array
    {
        return [
            'weekly' => [
                'label' => __('Wöchentlich', 'rh-backup'),
                'interval' => WEEK_IN_SECONDS,
            ],
            'rhbackup_monthly' => [
                'label' => __('Monatlich', 'rh-backup'),
                'interval' => 30 * DAY_IN_SECONDS,
            ],
            'rhbackup_quarterly' => [
                'label' => __('Alle drei Monate', 'rh-backup'),
                'interval' => 90 * DAY_IN_SECONDS,
            ],
        ];
    }

    /**
     * Wo die Sicherungen liegen: auf diesem Server oder ausschliesslich in Google Drive.
     *
     * Wer den Drive-Modus wählt, will die Kopie ausdrücklich nicht zusätzlich auf dem
     * Server haben, der ausfallen kann. Ohne bestehende Verbindung fällt der Modus
     * zurück auf lokal, sonst entstünde ein Zustand, in dem gar nicht gesichert wird.
     */
    public static function mode(): string
    {
        $value = (string) rhbp_setting(self::GROUP, self::MODE, self::MODE_LOCAL);

        if ($value !== self::MODE_DRIVE) {
            return self::MODE_LOCAL;
        }

        return (new Connection())->isConnected() ? self::MODE_DRIVE : self::MODE_LOCAL;
    }

    /**
     * Darf ein fertiges Archiv dauerhaft auf der Platte liegen bleiben?
     */
    public static function keepsLocalCopies(): bool
    {
        return self::mode() === self::MODE_LOCAL;
    }

    public static function interval(): string
    {
        $value = (string) rhbp_setting(self::GROUP, self::INTERVAL, self::DEFAULT_INTERVAL);

        return isset(self::intervals()[$value]) ? $value : self::DEFAULT_INTERVAL;
    }

    public static function intervalLabel(): string
    {
        $intervals = self::intervals();

        return (string) ($intervals[self::interval()]['label'] ?? '');
    }

    /**
     * Anzahl Kopien, die in Google Drive behalten werden.
     *
     * Mindestens 1: eine Rotation, die alles löscht, darf es nicht geben.
     */
    public static function keepCopies(): int
    {
        $value = (int) rhbp_setting(self::GROUP, self::KEEP_COPIES, self::DEFAULT_KEEP_COPIES);

        return max(1, min(365, $value));
    }

    public static function folderName(): string
    {
        $value = trim((string) rhbp_setting(self::GROUP, self::FOLDER_NAME, self::DEFAULT_FOLDER_NAME));

        return $value !== '' ? $value : self::DEFAULT_FOLDER_NAME;
    }

    /**
     * Unterordner je Website, damit mehrere Sites im selben Drive-Konto sauber
     * getrennt liegen und die Rotation sich nicht gegenseitig ins Gehege kommt.
     */
    public static function siteFolderName(): string
    {
        $host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);

        return $host !== '' ? $host : 'website';
    }

    public static function notifyEmail(): string
    {
        $value = trim((string) rhbp_setting(self::GROUP, self::NOTIFY_EMAIL, ''));
        if ($value !== '' && is_email($value)) {
            return $value;
        }

        return (string) get_option('admin_email', '');
    }

    public static function includeUploads(): bool
    {
        return self::scope() !== self::SCOPE_DATABASE;
    }

    /**
     * Was gesichert wird.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_DATABASE => [
                'label' => __('Nur die Datenbank', 'rh-backup'),
                'hint' => __('Inhalte, Einstellungen und Benutzer. Klein und schnell, aber ohne Bilder und ohne Code.', 'rh-backup'),
            ],
            self::SCOPE_UPLOADS => [
                'label' => __('Datenbank und Mediathek', 'rh-backup'),
                'hint' => __('Dazu alle hochgeladenen Bilder und Dateien. Für die meisten Websites die richtige Wahl.', 'rh-backup'),
            ],
            self::SCOPE_FULL => [
                'label' => __('Die komplette Website', 'rh-backup'),
                'hint' => __('Zusätzlich Themes, Plugins und WordPress selbst. Nach einem Totalverlust ist damit alles wieder da, dafür ist die Sicherung deutlich grösser.', 'rh-backup'),
            ],
        ];
    }

    public static function scope(): string
    {
        $wert = (string) rhbp_setting(self::GROUP, self::SCOPE, '');

        if (isset(self::scopes()[$wert])) {
            return $wert;
        }

        // Vor der Umstellung gab es nur ein Häkchen für die Mediathek. Wer es gesetzt
        // hatte, bekommt weiterhin dasselbe, ohne etwas tun zu müssen.
        return rhbp_setting(self::GROUP, self::INCLUDE_UPLOADS, true)
            ? self::SCOPE_UPLOADS
            : self::SCOPE_DATABASE;
    }

    public static function scopeLabel(): string
    {
        return (string) (self::scopes()[self::scope()]['label'] ?? '');
    }

    public static function isFullSite(): bool
    {
        return self::scope() === self::SCOPE_FULL;
    }

    /**
     * Gehört die wp-config.php in ein Voll-Backup?
     *
     * Standardmässig nein, und das aus einem handfesten Grund: die Datei enthält die
     * Zugangsdaten zur Datenbank und die Sicherheitsschlüssel im Klartext. In einem
     * Backup, das in einem Google-Konto landet, liegen diese Daten damit bei einem
     * Dritten. Wer das will, soll es ausdrücklich anhaken.
     */
    public static function includeConfig(): bool
    {
        return (bool) rhbp_setting(self::GROUP, self::INCLUDE_CONFIG, false);
    }

    /**
     * Client-ID der Anwendung. Geht den Betreiber der Website nichts an, siehe
     * {@see Credentials}. Eine Konstante in der wp-config.php hat Vorrang.
     */
    public static function clientId(): string
    {
        return self::fromConstant(self::CONST_CLIENT_ID) ?: Credentials::clientId();
    }

    public static function clientSecret(): string
    {
        return self::fromConstant(self::CONST_CLIENT_SECRET) ?: Credentials::clientSecret();
    }

    /**
     * Ist die Anbindung überhaupt eingerichtet? Ohne Zugangsdaten geht kein Verbinden.
     */
    public static function hasOAuthClient(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    private static function fromConstant(string $name): string
    {
        if (! defined($name)) {
            return '';
        }

        $value = constant($name);

        return is_string($value) ? trim($value) : '';
    }
}
