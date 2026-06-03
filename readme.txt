=== RH Backup ===
Contributors: robinherbeck
Tags: backup, database, export, import, migration
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Datenbank-Backup mit Export, Import und Restore. Pure PHP, läuft auf jedem Shared Hosting, ohne externe Dienste.

== Description ==

RH Backup sichert deine WordPress-Datenbank direkt aus dem Backend. Export, Import und Restore in einer aufgeräumten Oberfläche, die sich anfühlt wie ein Teil von WordPress.

Das Plugin ist bewusst schlank: kein Cron-Zwang, keine Cloud-Anbindung, keine externen Server. Die Sicherung passiert in reinem PHP und funktioniert deshalb auch auf einfachem Shared Hosting, wo viele Backup-Plugins an Speicher- oder Ausführungslimits scheitern.

= Was es kann =

* Datenbank als Archiv exportieren, optional mitsamt dem Uploads-Verzeichnis
* Backup wieder einspielen (Restore)
* Vorhandene Backups verwalten und gezielt löschen
* Download des Backups direkt im Browser

= Sicherheit =

* Backups landen mit zufälligem Dateinamen in einem geschützten Verzeichnis (keine erratbaren Pfade)
* Schutz per .htaccess gegen direkten Zugriff von außen
* Jede Aktion ist durch Capability-Prüfung und Nonce abgesichert

= Teil der rh-blueprint Kollektion =

RH Backup gehört zu einer Familie kleiner, fokussierter Plugins von Robin Herbeck. Jedes Modul läuft eigenständig, teilt sich aber dieselbe Oberfläche und dasselbe Einstellungs-System. Du installierst nur, was du wirklich brauchst.

== Installation ==

1. Plugin über Plugins -> Installieren hochladen oder aus dem Verzeichnis installieren.
2. Aktivieren.
3. Unter RH Blueprint -> Backup öffnen.
4. Export starten, Backup herunterladen oder bei Bedarf wieder einspielen.

== Frequently Asked Questions ==

= Brauche ich einen externen Dienst oder einen Account? =

Nein. RH Backup arbeitet komplett lokal auf deinem Server. Es werden keine Daten an Dritte übertragen.

= Läuft das Plugin auf Shared Hosting? =

Ja, das ist der Hauptanwendungsfall. Die Sicherung läuft in reinem PHP und kommt ohne mysqldump oder Shell-Zugriff aus.

= Werden auch die Uploads gesichert? =

Auf Wunsch. Beim Export lässt sich das Uploads-Verzeichnis optional mit einpacken.

= Wo werden die Backups gespeichert? =

In einem geschützten Verzeichnis innerhalb von wp-content, mit zufälligem Dateinamen und .htaccess-Schutz gegen direkten Zugriff.

= Kann ich ein Backup auf eine andere Seite übertragen? =

Ja, du kannst ein exportiertes Backup auf einer anderen Installation wieder einspielen. Für laufenden Abgleich zwischen zwei Seiten gibt es das Schwester-Plugin RH Sync.

== Changelog ==

= 0.2.3 =
* Erste Veröffentlichung im WordPress-Plugin-Verzeichnis.
* Export, Import und Restore der Datenbank, optional mit Uploads.
* Backup-Härtung: zufälliger Dateiname und .htaccess-Schutz im Backup-Verzeichnis.
* Aufgeräumte Oberfläche im nativen WordPress-Stil.

== Upgrade Notice ==

= 0.2.3 =
Erste Version im WordPress-Plugin-Verzeichnis.
