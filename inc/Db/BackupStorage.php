<?php

declare(strict_types=1);

namespace RhBackup\Db;

final class BackupStorage
{
    public const DATA_DIR = 'rh-blueprint-data';
    public const BACKUPS = 'backups';
    public const JOBS = 'jobs';
    public const AUTO_BACKUPS = 'auto-backups';

    /** @var array<int, string> */
    private const SUBDIRS = [self::BACKUPS, self::JOBS, self::AUTO_BACKUPS];

    public function ensureReady(): void
    {
        $base = $this->basePath();

        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }

        $this->writeGuardFiles($base);

        foreach (self::SUBDIRS as $sub) {
            $path = trailingslashit($base) . $sub;
            if (!is_dir($path)) {
                wp_mkdir_p($path);
            }
            $this->writeGuardFiles($path);
        }
    }

    public function basePath(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::DATA_DIR;
    }

    public function backupsPath(): string
    {
        return trailingslashit($this->basePath()) . self::BACKUPS;
    }

    public function jobsPath(): string
    {
        return trailingslashit($this->basePath()) . self::JOBS;
    }

    public function autoBackupsPath(): string
    {
        return trailingslashit($this->basePath()) . self::AUTO_BACKUPS;
    }

    /**
     * Loest einen Dateinamen innerhalb des Backup-Ordners auf.
     * Schuetzt gegen Path-Traversal, die aufgeloeste Datei MUSS unterhalb von $allowedRoot liegen.
     */
    public function resolveInside(string $allowedRoot, string $fileName): ?string
    {
        $fileName = basename($fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        $fullPath = trailingslashit($allowedRoot) . $fileName;
        $real = realpath($fullPath);
        $rootReal = realpath($allowedRoot);

        if ($real === false || $rootReal === false) {
            return null;
        }

        if (!str_starts_with($real, trailingslashit($rootReal))) {
            return null;
        }

        return $real;
    }

    /**
     * @return array<int, string> Liste der Datei-Basenames im Backups-Ordner, neueste zuerst.
     */
    public function listBackups(): array
    {
        $dir = $this->backupsPath();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(trailingslashit($dir) . '*.zip') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map('basename', $files);
    }

    public function reserveTempFile(string $prefix): string
    {
        $this->ensureReady();
        $name = sprintf('%s-%s.tmp', $prefix, wp_generate_password(8, false, false));

        return trailingslashit($this->jobsPath()) . $name;
    }

    private function writeGuardFiles(string $path): void
    {
        $htaccess = trailingslashit($path) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        $indexPhp = trailingslashit($path) . 'index.php';
        if (!file_exists($indexPhp)) {
            file_put_contents($indexPhp, "<?php\n// Silence is golden.\n");
        }
    }
}
