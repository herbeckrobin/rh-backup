<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhBackup\Offsite\Connection;
use RhBackup\Offsite\GoogleDrive;
use RhBackup\Offsite\Settings;
use RhDbEngine\Storage;

/**
 * Welche Ablageorte es gibt und welcher gerade gilt.
 *
 * Eine Stelle, an der die Antwort steht. Ohne sie müsste jeder Aufrufer selbst aus der
 * Einstellung ableiten, ob er es mit der Platte oder mit Google Drive zu tun hat, und
 * genau das war der Zustand, den dieser Umbau beseitigt.
 */
final class StoreRegistry
{
    /** @var array<string, BackupStore>|null */
    private ?array $stores = null;

    public function __construct(
        private readonly Storage $storage,
        private readonly GoogleDrive $drive,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, BackupStore>
     */
    public function all(): array
    {
        if ($this->stores === null) {
            $liste = [
                LocalStore::ID => new LocalStore($this->storage),
                DriveStore::ID => new DriveStore($this->drive, $this->connection),
            ];

            /**
             * Weitere Ablageorte.
             *
             * @param array<string, BackupStore> $liste
             */
            $gefiltert = (array) apply_filters('rh-backup/stores', $liste);

            $this->stores = array_filter(
                $gefiltert,
                static fn ($s): bool => $s instanceof BackupStore
            );
        }

        return $this->stores;
    }

    /**
     * Der Ort, der gerade gilt.
     *
     * Ist der eingestellte nicht benutzbar, etwa weil die Verbindung zu Google getrennt
     * wurde, fällt er auf die Platte zurück. Sonst entstünde ein Zustand, in dem gar
     * nicht gesichert wird.
     */
    public function current(): BackupStore
    {
        $gewaehlt = (string) rhbp_setting(Settings::GROUP, Settings::MODE, LocalStore::ID);
        $stores = $this->all();

        if (isset($stores[$gewaehlt]) && $stores[$gewaehlt]->isReady()) {
            return $stores[$gewaehlt];
        }

        return $stores[LocalStore::ID];
    }

    public function get(string $id): ?BackupStore
    {
        return $this->all()[$id] ?? null;
    }

    public function local(): LocalStore
    {
        /** @var LocalStore $store */
        $store = $this->all()[LocalStore::ID];

        return $store;
    }

    /**
     * Die Orte, an denen etwas liegt, das nicht am aktuellen Ort liegt.
     *
     * Grundlage für die Frage beim Umschalten: was jetzt woanders liegt, gehört entweder
     * mitgenommen oder weg.
     *
     * @return array<string, array{store: BackupStore, count: int, bytes: int}>
     */
    public function strays(): array
    {
        $aktuell = $this->current()->id();
        $ergebnis = [];

        foreach ($this->all() as $id => $store) {
            if ($id === $aktuell || ! $store->isReady()) {
                continue;
            }

            $eintraege = $store->list();
            if ($eintraege === []) {
                continue;
            }

            $ergebnis[$id] = [
                'store' => $store,
                'count' => count($eintraege),
                'bytes' => array_sum(array_map(static fn (BackupEntry $e): int => $e->size, $eintraege)),
            ];
        }

        return $ergebnis;
    }
}
