<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhDbEngine\Archive\ArchiveStream;
use RhDbEngine\Archive\FileArchiveStream;
use RhDbEngine\Storage;

/**
 * Sicherungen auf diesem Server.
 *
 * Sie liegen in Unterordnern nach Anlass. Die Sicherungskopien, die vor einem Import
 * entstehen, sind bewusst nicht dabei: sie sind ein Werkzeug des Imports und werden im
 * Fehlerfall sofort wieder eingespielt. In einer Liste, aus der man auswählt, hätten sie
 * nichts verloren, und mitwandern dürfen sie erst recht nicht.
 */
final class LocalStore implements BackupStore
{
    public const ID = 'local';

    public function __construct(private readonly Storage $storage)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Auf diesem Server', 'rh-backup');
    }

    public function isReady(): bool
    {
        return true;
    }

    public function notReadyReason(): string
    {
        return '';
    }

    /**
     * @return array<int, BackupEntry>
     */
    public function list(): array
    {
        $eintraege = [];

        foreach ($this->storage->listBackups() as $relativ) {
            $kind = BackupKind::fromPath($relativ);

            // Sicherungskopien gehören nicht in die Auswahl, siehe Klassenkommentar.
            if ($kind === BackupKind::PRESYNC) {
                continue;
            }

            $pfad = $this->storage->resolveInside($this->storage->backupsPath(), $relativ);
            if ($pfad === null || ! is_file($pfad)) {
                continue;
            }

            $eintraege[] = new BackupEntry(
                ref: $relativ,
                name: basename($relativ),
                size: (int) filesize($pfad),
                time: (int) (filemtime($pfad) ?: 0),
                kind: $kind,
            );
        }

        return $eintraege;
    }

    public function find(string $ref): ?BackupEntry
    {
        foreach ($this->list() as $eintrag) {
            if ($eintrag->ref === $ref) {
                return $eintrag;
            }
        }

        return null;
    }

    public function delete(string $ref): bool
    {
        $pfad = $this->path($ref);
        if ($pfad === null) {
            return false;
        }

        wp_delete_file($pfad);

        return ! is_file($pfad);
    }

    public function open(string $ref): ArchiveStream
    {
        $pfad = $this->path($ref);
        if ($pfad === null) {
            throw new \RuntimeException(__('Diese Sicherung liegt nicht mehr auf dem Server.', 'rh-backup'));
        }

        return new FileArchiveStream($pfad);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{offset: int, done: bool, state: array<string, mixed>}
     */
    public function receive(string $name, ArchiveStream $source, int $offset, array $state, float $budget): array
    {
        $ziel = $this->incomingPath($name);
        $gesamt = $source->size();

        $handle = fopen($ziel, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new \RuntimeException(__('Die Sicherung konnte nicht geschrieben werden.', 'rh-backup'));
        }

        $deadline = microtime(true) + max(0.1, $budget);

        try {
            while ($offset < $gesamt && microtime(true) < $deadline) {
                $daten = $source->readAt($offset, self::CHUNK);
                if ($daten === '') {
                    throw new \RuntimeException(__('Die Sicherung liefert keine Daten mehr, obwohl sie noch nicht vollständig ist.', 'rh-backup'));
                }

                if (fwrite($handle, $daten) !== strlen($daten)) {
                    throw new \RuntimeException(__('Die Sicherung konnte nicht vollständig geschrieben werden. Bitte den Plattenplatz prüfen.', 'rh-backup'));
                }

                $offset += strlen($daten);
            }
        } finally {
            fclose($handle);
        }

        if ($offset < $gesamt) {
            return ['offset' => $offset, 'done' => false, 'state' => $state];
        }

        // Erst wenn alles da ist, bekommt die Datei ihren richtigen Namen. Eine halb
        // geschriebene Sicherung darf nie wie eine vollständige aussehen.
        $endgueltig = trailingslashit($this->storage->backupsSubPath($this->kindFromName($name))) . $name;
        if (! @rename($ziel, $endgueltig)) {
            throw new \RuntimeException(__('Die Sicherung konnte nicht abgelegt werden.', 'rh-backup'));
        }

        $this->storage->protectFile($endgueltig);

        return ['offset' => $offset, 'done' => true, 'state' => $state];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function abortReceive(string $name, array $state): void
    {
        $teil = $this->incomingPath($name);
        if (is_file($teil)) {
            wp_delete_file($teil);
        }
    }

    /**
     * Absoluter Pfad einer Sicherung, oder null.
     */
    public function path(string $ref): ?string
    {
        $pfad = $this->storage->resolveInside($this->storage->backupsPath(), $ref);

        return $pfad !== null && is_file($pfad) ? $pfad : null;
    }

    private const CHUNK = 4 * 1024 * 1024;

    /**
     * Wohin eine noch unvollständige Sicherung geschrieben wird.
     */
    private function incomingPath(string $name): string
    {
        return trailingslashit($this->storage->backupsSubPath($this->kindFromName($name)))
            . $name . '.part';
    }

    /**
     * Zu welchem Anlass gehört eine Datei, gemessen an ihrem Namen?
     *
     * Beim Umzug von einem anderen Ablageort gibt es keinen Unterordner, aus dem man es
     * ablesen könnte, nur den Namen. Deshalb steht der Anlass dort drin.
     */
    private function kindFromName(string $name): string
    {
        foreach (array_keys(BackupKind::all()) as $kind) {
            if (str_contains($name, '-' . $kind . '-')) {
                return $kind;
            }
        }

        return BackupKind::MANUAL;
    }
}
