<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhDbEngine\Archive\ArchiveIndex;
use RhDbEngine\Archive\ArchiveStream;
use RhDbEngine\Archive\ScanCursor;
use RhDbEngine\Archive\SiteScanner;
use RhDbEngine\Archive\StreamingZipWriter;
use RhDbEngine\ExportCursor;
use RhDbEngine\Exporter;
use RhDbEngine\Storage;

/**
 * Ein Archiv der ganzen Website, das beim Lesen erst entsteht.
 *
 * Zusammengesetzt aus drei Teilen, in dieser Reihenfolge im Archiv:
 *   db.sql          der Datenbank-Dump
 *   manifest.json   was drin ist und woher es kommt
 *   root/…          der Dateibaum
 *
 * Der Dump steht vorn, weil er beim Zurückspielen zuerst gebraucht wird. Er entsteht
 * dabei ganz gewöhnlich über die vorhandene Engine und liegt danach als Datei im
 * Arbeitsverzeichnis. Damit ist er für das Archiv nichts anderes als jede andere Datei
 * auch, und es braucht weder ein Stückeln des Dumps noch einen Eingriff in den Import.
 *
 * Was auf der Platte liegt: die Dateiliste, die Suchhilfe, die Prüfsummen, das
 * Inhaltsverzeichnis und der Dump. Bei einer Website mit 900 MB Dateien und 50 MB
 * Datenbank sind das rund 55 MB statt 900 MB. Das komplette Archiv entsteht nie.
 *
 * Der Ablauf läuft in Runden, damit er über viele Requests verteilt werden kann:
 * Datenbank, Durchgang, Prüfsummen, fertig.
 */
final class FullSiteArchive
{
    public const PHASE_DATABASE = 'database';
    public const PHASE_SCAN = 'scan';
    public const PHASE_CHECKSUMS = 'checksums';
    public const PHASE_READY = 'ready';

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly Exporter $exporter,
        private readonly string $workdir,
        private array $state = [],
    ) {
        $this->state = array_merge([
            'phase' => self::PHASE_DATABASE,
            'export_cursor' => [],
            'scan_cursor' => [],
            'index_count' => 0,
            'index_bytes' => 0,
            'index_offset' => 0,
            'index_names' => 0,
            'files' => 0,
            'include_files' => true,
        ], $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function resume(Storage $storage, Exporter $exporter, string $workdir, array $state): self
    {
        return new self($storage, $exporter, $workdir, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function phase(): string
    {
        return (string) $this->state['phase'];
    }

    public function isReady(): bool
    {
        return $this->phase() === self::PHASE_READY;
    }

    public function files(): int
    {
        return (int) $this->state['files'];
    }

    /**
     * Bringt die Vorbereitung um ein Zeitbudget voran.
     */
    public function advance(float $budget): void
    {
        match ($this->phase()) {
            self::PHASE_DATABASE => $this->stepDatabase($budget),
            self::PHASE_SCAN => $this->stepScan($budget),
            self::PHASE_CHECKSUMS => $this->stepChecksums($budget),
            default => null,
        };
    }

    /**
     * Woran gerade gearbeitet wird, für die Anzeige.
     */
    public function detail(): string
    {
        return match ($this->phase()) {
            self::PHASE_DATABASE => __('Datenbank wird gesichert', 'rh-backup'),
            self::PHASE_SCAN => sprintf(
                /* translators: %d: number of files */
                __('Dateien werden erfasst, %d gefunden', 'rh-backup'),
                (int) $this->state['files']
            ),
            self::PHASE_CHECKSUMS => sprintf(
                /* translators: %1$d: done, %2$d: total */
                __('Prüfsummen, %1$d von %2$d', 'rh-backup'),
                $this->index()->crcCount(),
                (int) $this->state['index_count']
            ),
            default => __('Bereit zur Übertragung', 'rh-backup'),
        };
    }

    /**
     * Das fertige Archiv als Lesequelle.
     */
    public function stream(): ArchiveStream
    {
        if (! $this->isReady()) {
            throw new \RuntimeException(__('Das Archiv ist noch nicht vollständig vorbereitet.', 'rh-backup'));
        }

        return new StreamingZipWriter($this->index(), $this->workdir . '/trailer.bin');
    }

    public function size(): int
    {
        return $this->stream()->size();
    }

    /**
     * Entfernt alles, was zur Vorbereitung angelegt wurde.
     */
    public function cleanup(): void
    {
        foreach ((array) glob($this->workdir . '/*') as $datei) {
            if (is_file($datei)) {
                wp_delete_file($datei);
            }
        }
    }

    // ============================================================
    // Runden
    // ============================================================

    /**
     * Dump und Manifest über die vorhandene Engine, aber ohne deren Archiv.
     */
    private function stepDatabase(float $budget): void
    {
        $cursor = $this->state['export_cursor'] === []
            ? new ExportCursor(
                workdir: $this->workdir,
                includeUploads: false,
                stopBefore: ExportCursor::PHASE_ZIP_DB,
            )
            : ExportCursor::fromArray($this->state['export_cursor']);

        $cursor = $this->exporter->exportStep($cursor, $budget);
        $this->state['export_cursor'] = $cursor->toArray();

        if ($cursor->phase !== ExportCursor::PHASE_ZIP_DB) {
            return;
        }

        $sql = (string) $cursor->sqlPath;
        $manifest = (string) $cursor->manifestPath;

        if (! is_file($sql) || ! is_file($manifest)) {
            throw new \RuntimeException(__('Der Datenbank-Teil des Backups wurde nicht erzeugt.', 'rh-backup'));
        }

        $this->extendManifest($manifest);

        // Beide als erste Einträge, damit sie im Archiv vorn stehen.
        $index = $this->index();
        $index->reset();
        $index->append('db.sql', $sql, (int) filesize($sql), (int) (filemtime($sql) ?: 0));
        $index->append('manifest.json', $manifest, (int) filesize($manifest), (int) (filemtime($manifest) ?: 0));
        $index->flush();
        $this->rememberIndex($index);

        $this->state['phase'] = $this->state['include_files'] ? self::PHASE_SCAN : self::PHASE_CHECKSUMS;
    }

    /**
     * Ergänzt das Manifest um alles, was ein Auspacken von Hand planbar macht.
     *
     * Ein Voll-Backup lässt sich nicht über das Plugin zurückspielen, das ist Absicht:
     * ein Restore schreibt per Definition ausführbaren Code, und über einen Sync käme
     * dieser Code von einem entfernten Rechner. Wer es von Hand macht, muss dafür aber
     * wissen, welcher Ordner im Archiv wohin gehört und was bewusst fehlt.
     */
    private function extendManifest(string $pfad): void
    {
        $inhalt = @file_get_contents($pfad);
        if ($inhalt === false) {
            return;
        }

        $daten = json_decode($inhalt, true);
        if (! is_array($daten)) {
            return;
        }

        $daten['archive_kind'] = 'full-site';
        $daten['roots'] = self::roots();
        $daten['excluded_directories'] = ExcludeRules::directories();
        $daten['excluded_anywhere'] = ExcludeRules::anywhere();
        $daten['excluded_files'] = ExcludeRules::optionalFiles();
        $daten['includes_wp_config'] = \RhBackup\Offsite\Settings::includeConfig();
        $daten['restore_hint'] = implode(' ', [
            'Dieses Archiv enthält die komplette Website und wird von Hand ausgepackt, nicht über das Plugin.',
            'Die Ordner im Archiv entsprechen den Pfaden unter "roots".',
            'Die Datenbank liegt als db.sql bei und wird getrennt eingespielt.',
            'Vorsicht bei wp-config.php: sie gehört zur Zielinstallation und darf nicht blind überschrieben werden.',
        ]);

        $neu = wp_json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($neu)) {
            file_put_contents($pfad, $neu);
        }
    }

    private function stepScan(float $budget): void
    {
        $index = $this->index();

        $cursor = $this->state['scan_cursor'] === []
            ? ScanCursor::start(self::roots())
            : ScanCursor::fromArray($this->state['scan_cursor']);

        $scanner = new SiteScanner(ExcludeRules::callback());
        $cursor = $scanner->scanStep($cursor, $index, $budget);
        $index->flush();

        $this->state['scan_cursor'] = $cursor->toArray();
        $this->state['files'] = $cursor->files;
        $this->rememberIndex($index);

        if ($cursor->done) {
            $this->state['phase'] = self::PHASE_CHECKSUMS;
        }
    }

    /**
     * Die Prüfsummen. Jede Datei wird dafür einmal gelesen.
     *
     * Nötig, weil die Prüfsumme im Kopfsatz jeder Datei steht, also vor deren Inhalt.
     * Ohne sie liesse sich das Archiv zwar öffnen, aber jedes Standardwerkzeug würde
     * es als beschädigt melden.
     */
    private function stepChecksums(float $budget): void
    {
        $index = $this->index();
        $deadline = microtime(true) + max(0.1, $budget);
        $gesamt = (int) $this->state['index_count'];

        while ($index->crcCount() < $gesamt) {
            $eintrag = $index->at($index->crcCount());
            if ($eintrag === null) {
                break;
            }

            $hash = @hash_file('crc32b', $eintrag['source']);
            $index->appendCrc($hash === false ? 0 : (int) hexdec($hash));

            if (microtime(true) >= $deadline) {
                $index->flush();

                return;
            }
        }

        $index->flush();

        if ($index->crcCount() >= $gesamt) {
            $this->state['phase'] = self::PHASE_READY;
        }
    }

    // ============================================================
    // Helfer
    // ============================================================

    /**
     * Der Index, immer mit dem gemerkten Stand.
     *
     * Das Objekt selbst ist billig und wird bei jedem Zugriff neu gebaut, die Daten
     * liegen ja in Dateien. Was nicht in den Dateien steht, sind die laufenden Summen,
     * und ohne sie käme ein Archiv der Grösse null heraus.
     */
    private function index(): ArchiveIndex
    {
        $index = new ArchiveIndex($this->workdir . '/index.tsv', StreamingZipWriter::overhead());
        $index->resume(
            (int) $this->state['index_count'],
            (int) $this->state['index_bytes'],
            (int) $this->state['index_offset'],
            (int) $this->state['index_names']
        );

        return $index;
    }

    private function rememberIndex(ArchiveIndex $index): void
    {
        $this->state['index_count'] = $index->count();
        $this->state['index_bytes'] = $index->bytes();
        $this->state['index_offset'] = $index->archiveOffset();
        $this->state['index_names'] = $index->nameBytes();
    }

    /**
     * Die Wurzeln, aus denen sich eine WordPress-Installation zusammensetzt.
     *
     * Ein einziger Pfad reicht nicht: bei manchen Layouts liegt wp-content neben und
     * nicht unter dem WordPress-Verzeichnis. Was ineinander liegt, fällt weg, sonst
     * landet die halbe Installation zweimal im Archiv.
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $roots = [
            'root' => ABSPATH,
            'content' => WP_CONTENT_DIR,
            'plugins' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
            'themes' => get_theme_root(),
            'mu-plugins' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
        ];

        return SiteScanner::dropNested(array_filter($roots));
    }
}
