<?php

declare(strict_types=1);

namespace RhBackup\Storage;

/**
 * Was nicht ins Archiv gehört.
 *
 * Zwei Sorten von Ausschlüssen, und beide sind wichtig aus verschiedenen Gründen.
 *
 * Die einen verhindern Unsinn: der eigene Datenordner darf nicht ins Archiv, sonst
 * sichert das Backup seine eigenen Backups, und zwar bei jedem Lauf erneut. Liegt der
 * Datenordner wie üblich unter wp-content, ist das kein Sonderfall, sondern sicher.
 * Dasselbe gilt für die Archive anderer Backup-Plugins.
 *
 * Die anderen halten das Archiv stabil: der Aufbau wird beim Durchgang festgeschrieben,
 * und ändert sich danach eine Datei, verschieben sich alle folgenden Positionen. Bei
 * einer Laufzeit von zwanzig Minuten auf einer Seite mit Zwischenspeicher ist das keine
 * theoretische Möglichkeit, sondern der Normalfall. Cache-Verzeichnisse und Protokolle
 * gehören deshalb nicht hinein, und zwar nicht aus Sparsamkeit.
 */
final class ExcludeRules
{
    /**
     * Verzeichnisse, die immer draussen bleiben. Angegeben als Pfad relativ zur Wurzel,
     * ohne führenden Schrägstrich.
     *
     * @return array<string, array<int, string>> Wurzelname => Pfade
     */
    public static function directories(): array
    {
        $regeln = [
            'root' => [
                'wp-content/' . \RhDbEngine\Storage::DATA_DIR,
                'wp-content/cache',
                'wp-content/upgrade',
                'wp-content/upgrade-temp-backup',
                'wp-content/uploads/cache',
                'wp-content/ai1wm-backups',
                'wp-content/updraft',
                'wp-content/backups-dup-pro',
            ],
            'content' => [
                \RhDbEngine\Storage::DATA_DIR,
                'cache',
                'upgrade',
                'upgrade-temp-backup',
                'uploads/cache',
                'ai1wm-backups',
                'updraft',
                'backups-dup-pro',
            ],
        ];

        /**
         * Weitere Verzeichnisse, die nicht ins Archiv sollen.
         *
         * @param array<string, array<int, string>> $regeln
         */
        return (array) apply_filters('rh-backup/exclude_directories', $regeln);
    }

    /**
     * Namen, die überall ausgeschlossen sind, egal wo sie auftauchen.
     *
     * @return array<int, string>
     */
    public static function anywhere(): array
    {
        $namen = [
            'node_modules',
            '.git',
            '.svn',
            '.hg',
            '.DS_Store',
            'Thumbs.db',
        ];

        /**
         * Weitere Namen, die überall ausgeschlossen sind.
         *
         * @param array<int, string> $namen
         */
        return array_map('strval', (array) apply_filters('rh-backup/exclude_anywhere', $namen));
    }

    /**
     * Endungen, die überall ausgeschlossen sind.
     *
     * @return array<int, string>
     */
    public static function extensions(): array
    {
        /**
         * Weitere Dateiendungen, die nicht ins Archiv sollen.
         *
         * @param array<int, string> $endungen
         */
        return array_map('strval', (array) apply_filters('rh-backup/exclude_extensions', ['log']));
    }

    /**
     * Einzelne Dateien, die nur auf Wunsch mitgesichert werden.
     *
     * @return array<int, string>
     */
    public static function optionalFiles(): array
    {
        if (\RhBackup\Offsite\Settings::includeConfig()) {
            return [];
        }

        // Enthält Datenbank-Zugang und Sicherheitsschlüssel im Klartext. Landet die
        // Sicherung bei einem Dritten, liegen diese Daten dort mit.
        return ['wp-config.php'];
    }

    /**
     * Die Prüfung, die der Durchgang für jeden Eintrag aufruft.
     *
     * @return callable(string, string, bool): bool
     */
    public static function callback(): callable
    {
        $verzeichnisse = self::directories();
        $ueberall = array_flip(self::anywhere());
        $endungen = array_flip(array_map('strtolower', self::extensions()));
        $einzelne = array_flip(self::optionalFiles());

        return static function (string $relativ, string $rootName, bool $istVerzeichnis) use ($verzeichnisse, $ueberall, $endungen, $einzelne): bool {
            $name = basename($relativ);

            if (isset($ueberall[$name])) {
                return true;
            }

            if (! $istVerzeichnis && isset($einzelne[$relativ])) {
                return true;
            }

            if (! $istVerzeichnis) {
                $endung = strtolower((string) pathinfo($relativ, PATHINFO_EXTENSION));
                if ($endung !== '' && isset($endungen[$endung])) {
                    return true;
                }
            }

            foreach ($verzeichnisse[$rootName] ?? [] as $ausgeschlossen) {
                $ausgeschlossen = trim($ausgeschlossen, '/');
                if ($ausgeschlossen === '') {
                    continue;
                }

                // Der Ordner selbst und alles darunter.
                if ($relativ === $ausgeschlossen || str_starts_with($relativ, $ausgeschlossen . '/')) {
                    return true;
                }
            }

            return false;
        };
    }
}
