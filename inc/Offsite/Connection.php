<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Hält die Verbindung zum Google-Konto des Kunden: Refresh-Token, Access-Token, Konto-Name.
 *
 * Der Refresh-Token liegt verschlüsselt in einer eigenen Option (autoload=no) und ist
 * das eigentliche Geheimnis: mit ihm lässt sich jederzeit ein neuer Access-Token holen.
 * Der Access-Token ist kurzlebig (rund eine Stunde) und liegt im Transient, damit ein
 * mehrstündiger Upload ihn nicht bei jedem Tick neu anfordert.
 */
final class Connection
{
    private const ACCESS_TRANSIENT = 'rhbackup_offsite_access';

    /** Sicherheitsabstand, damit ein Token nicht mitten im Chunk abläuft. */
    private const EXPIRY_MARGIN = 300;

    public function isConnected(): bool
    {
        return $this->refreshToken() !== '';
    }

    public function refreshToken(): string
    {
        return Secret::decrypt((string) get_option(Settings::OPTION_REFRESH_TOKEN, ''));
    }

    /**
     * Speichert einen frisch erhaltenen Refresh-Token.
     *
     * Ohne funktionierende Verschlüsselung wird bewusst NICHT gespeichert: ein Token
     * im Klartext in der Datenbank ist schlimmer als eine fehlende Verbindung, und ein
     * leerer Wert würde später wie "nie verbunden" aussehen.
     *
     * @throws \RuntimeException wenn libsodium fehlt.
     */
    public function storeRefreshToken(string $token, string $account = ''): void
    {
        if ($token === '') {
            return;
        }

        if (! Secret::available()) {
            throw new \RuntimeException(
                __('Die PHP-Erweiterung Sodium fehlt. Ohne sie kann der Zugang nicht verschlüsselt gespeichert werden.', 'rh-backup')
            );
        }

        update_option(Settings::OPTION_REFRESH_TOKEN, Secret::encrypt($token), false);

        if ($account !== '') {
            update_option(Settings::OPTION_ACCOUNT, sanitize_text_field($account), false);
        }
    }

    public function account(): string
    {
        return (string) get_option(Settings::OPTION_ACCOUNT, '');
    }

    /**
     * Trennt die Verbindung lokal. Der Zugriff im Google-Konto bleibt bestehen, bis
     * der Nutzer ihn dort widerruft, darauf weist die Oberfläche hin.
     */
    public function disconnect(): void
    {
        delete_option(Settings::OPTION_REFRESH_TOKEN);
        delete_option(Settings::OPTION_ACCOUNT);
        delete_option(Settings::OPTION_FOLDER_ID);
        delete_transient(self::ACCESS_TRANSIENT);
    }

    public function cachedAccessToken(): string
    {
        $cached = get_transient(self::ACCESS_TRANSIENT);

        return is_string($cached) ? $cached : '';
    }

    public function storeAccessToken(string $token, int $expiresIn): void
    {
        $ttl = max(60, $expiresIn - self::EXPIRY_MARGIN);
        set_transient(self::ACCESS_TRANSIENT, $token, $ttl);
    }

    public function forgetAccessToken(): void
    {
        delete_transient(self::ACCESS_TRANSIENT);
    }

    /**
     * Merkt sich den Ordner in Drive, damit er nicht bei jedem Lauf gesucht wird.
     */
    public function folderId(): string
    {
        return (string) get_option(Settings::OPTION_FOLDER_ID, '');
    }

    public function storeFolderId(string $id): void
    {
        update_option(Settings::OPTION_FOLDER_ID, sanitize_text_field($id), false);
    }

    public function forgetFolderId(): void
    {
        delete_option(Settings::OPTION_FOLDER_ID);
    }
}
