<?php

declare(strict_types=1);

namespace RhBackup;

use RhBackup\Db\Exporter;
use RhBackup\Db\Importer;

/**
 * Öffentliche API von rh-backup.
 *
 * Wird beim Core-Boot als Service `backup` in der Service-Registry angemeldet.
 * Andere rh-Plugins (vor allem rh-sync) ziehen sie darüber, statt die Db-Klassen
 * direkt anzufassen. So bleibt die DB-Schicht hinter einer stabilen Schnittstelle.
 *
 * Versionierung: bei additiven Änderungen VERSION erhöhen. Konsumenten fordern
 * eine Mindestversion an (`services()->get('backup', $min)`).
 */
final class Api
{
    public const VERSION = 1;

    public function __construct(
        private readonly Exporter $exporter,
        private readonly Importer $importer,
    ) {
    }

    public function apiVersion(): int
    {
        return self::VERSION;
    }

    /**
     * Erstellt ein Backup-ZIP und gibt den Dateipfad zurück.
     *
     * @param array<int, string> $excludedTables Tabellen, die nicht in den Dump wandern.
     */
    public function createBackup(bool $includeUploads = false, array $excludedTables = []): string
    {
        return $this->exporter->createBackup($includeUploads, $excludedTables);
    }

    /**
     * Spielt ein Backup-ZIP ein.
     *
     * Der optionale Table-Filter erlaubt selektiven Import (z.B. der Sync-Scope
     * von rh-sync). Ohne Filter wird die komplette SQL eingespielt.
     *
     * @param (callable(string): bool)|null $tableFilter fn(vollqualifizierter Tabellenname): bool
     * @return array<string, mixed> Manifest-Daten des Backups
     */
    public function importFromFile(string $zipPath, ?callable $tableFilter = null, bool $includeUploads = true): array
    {
        return $this->importer->importFromFile($zipPath, $tableFilter, $includeUploads);
    }
}
