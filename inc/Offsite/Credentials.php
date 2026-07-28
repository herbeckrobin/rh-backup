<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Zugangsdaten der Anwendung selbst, nicht die des Kunden.
 *
 * Der Kunde bekommt davon nichts zu sehen und muss nichts eintragen. Er drückt einen
 * Knopf, bestätigt in seinem Google-Konto und ist fertig.
 *
 * Warum das hier fest hinterlegt ist und nicht in einem Eingabefeld: bei installierten
 * Anwendungen und Geräten ist das Client-Secret kein echtes Geheimnis. Google schreibt
 * dazu wörtlich, dass der Wert in den Quelltext der Anwendung eingebettet wird und "in
 * diesem Zusammenhang offensichtlich nicht als Geheimnis behandelt" wird. RFC 8252 sagt
 * dasselbe: ein statisch mitgeliefertes Secret in einer verteilten Anwendung ist kein
 * vertrauliches Secret, der Client gilt als öffentlich. Die eigentliche Absicherung ist,
 * dass der Nutzer die Freigabe in seinem eigenen Konto bestätigen muss.
 *
 * WICHTIG: Die Werte unten bleiben im Repository LEER. Eingesetzt werden sie erst beim
 * Bauen des Release-Pakets, aus den GitHub-Secrets GDRIVE_CLIENT_ID und
 * GDRIVE_CLIENT_SECRET (siehe .github/workflows/release.yml). So stehen sie weder im
 * öffentlichen Repository noch in der Git-Historie, sind aber im ausgelieferten ZIP
 * enthalten. Der Kunde installiert das Plugin und verbindet sein Konto, mehr nicht.
 *
 * Der Preis dieser Lösung, bewusst gewählt: alle Websites teilen sich einen Client, den
 * jeder aus dem Paket auslesen kann. Missbraucht ihn jemand und Google sperrt ihn,
 * verlieren sämtliche Kundenseiten gleichzeitig ihre Verbindung. Neuen Client anlegen,
 * Secrets tauschen, Release bauen ist dann der Weg zurück.
 *
 * Alternativ lassen sich beide Werte pro Website über Konstanten in der wp-config.php
 * setzen, die haben Vorrang:
 *
 *     define('RH_BACKUP_GDRIVE_CLIENT_ID', '...');
 *     define('RH_BACKUP_GDRIVE_CLIENT_SECRET', '...');
 *
 * Ist nichts gesetzt, meldet die Oberfläche, dass die Anbindung noch nicht eingerichtet
 * ist, statt den Kunden mit einem Formular zu behelligen, das ihn nichts angeht.
 */
final class Credentials
{
    /**
     * Client-ID des OAuth-Clients vom Typ "TVs and Limited Input devices".
     * Die ID ist ohnehin öffentlich, sie steht in jeder Anfrage an Google.
     */
    public const CLIENT_ID = '';

    /**
     * Zugehöriges Secret. Siehe Klassen-Kommentar: bei diesem Client-Typ kein
     * vertraulicher Wert im eigentlichen Sinn.
     */
    public const CLIENT_SECRET = '';

    public static function clientId(): string
    {
        return self::CLIENT_ID;
    }

    public static function clientSecret(): string
    {
        return self::CLIENT_SECRET;
    }
}
