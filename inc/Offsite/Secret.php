<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Verschlüsselt Geheimnisse (OAuth-Tokens, Client-Secret) at-rest mit libsodium.
 *
 * Der Schlüssel wird aus den WordPress-Salts (`wp_salt('auth')`) abgeleitet. Die Salts
 * liegen bei einer Standard-Installation in der wp-config.php, NICHT in der Datenbank.
 * Ein reiner Datenbank-Leak gibt die Tokens damit nicht preis, man bräuchte zusätzlich
 * die wp-config.php. libsodium ist in PHP 8.1+ eingebaut.
 *
 * Gleiches Verfahren wie rh-smtp für das SMTP-Passwort. Bewusst als eigene Klasse und
 * nicht im Core: der Core wird in jedes Modul gebündelt, und eine Krypto-Hilfe dort
 * wäre eine Änderung an allen Modulen. Sollte ein drittes Modul Geheimnisse speichern,
 * ist das der Moment, es in den Core zu heben.
 *
 * Wichtig: Ändern sich die Salts in der wp-config.php, sind die gespeicherten Tokens
 * nicht mehr entschlüsselbar. Der Nutzer muss Google Drive dann neu verbinden. Das ist
 * gewollt, ein stiller Fehlschlag wäre schlimmer, siehe Connection::isConnected().
 */
final class Secret
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || ! self::available()) {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '' || ! self::available()) {
            return '';
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        // 32-Byte-Schlüssel aus den WP-Secrets (wp-config), stabil pro Site.
        return sodium_crypto_generichash(wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
