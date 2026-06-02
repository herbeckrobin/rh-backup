<?php

declare(strict_types=1);

namespace RhBackup\Admin;

use RhBackup\Db\BackupStorage;
use RhBackup\Db\Exporter;
use RhBackup\Db\Importer;
use RhBlueprint\Core\Settings\SettingsPage;

final class DbToolsPage
{
    public const TAB_ID = 'tools';
    public const CAPABILITY = 'manage_options';
    public const NONCE_EXPORT = 'rhbp_db_export';
    public const NONCE_IMPORT = 'rhbp_db_import';
    public const NONCE_DELETE = 'rhbp_db_delete';

    public function __construct(
        private readonly BackupStorage $storage,
        private readonly Exporter $exporter,
        private readonly Importer $importer
    ) {
    }

    public function boot(): void
    {
        add_action('admin_post_rhbp_db_export', [$this, 'handleExport']);
        add_action('admin_post_rhbp_db_import', [$this, 'handleImport']);
        add_action('admin_post_rhbp_db_delete', [$this, 'handleDelete']);
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'renderInlineTools']);
    }

    public function renderInlineMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $message = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';
        if ($message === '') {
            return;
        }

        $map = [
            'export_ok' => ['success', __('Backup erfolgreich erstellt.', 'rh-blueprint')],
            'export_failed' => ['error', __('Backup konnte nicht erstellt werden.', 'rh-blueprint')],
            'import_ok' => ['success', __('Backup erfolgreich wiederhergestellt.', 'rh-blueprint')],
            'import_failed' => ['error', __('Import fehlgeschlagen.', 'rh-blueprint')],
            'import_not_confirmed' => ['warning', __('Bitte "JA LOESCHEN" eingeben um den Import zu bestätigen.', 'rh-blueprint')],
            'import_no_file' => ['warning', __('Kein Backup ausgewählt.', 'rh-blueprint')],
            'import_invalid_path' => ['error', __('Ungültiger Backup-Pfad.', 'rh-blueprint')],
            'delete_ok' => ['success', __('Backup gelöscht.', 'rh-blueprint')],
        ];

        if (!isset($map[$message])) {
            return;
        }

        [$type, $text] = $map[$message];
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    public function renderInlineTools(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        echo '<div class="rhbp-db-tools">';
        echo '<h2 class="rhbp-db-tools__heading">' . esc_html__('Datenbank & Backups', 'rh-blueprint') . '</h2>';
        echo '<p class="rhbp-db-tools__intro">' . esc_html__('Export, Import und Verwaltung von DB-Snapshots. Basis für das Sync Network.', 'rh-blueprint') . '</p>';

        $this->renderExportCard();
        $this->renderImportCard();
        $this->renderBackupList();
        echo '</div>';
    }

    public function handleExport(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_EXPORT);

        $includeUploads = !empty($_POST['include_uploads']);

        try {
            $zipPath = $this->exporter->createBackup($includeUploads);
        } catch (\Throwable $e) {
            $this->redirect('export_failed');
        }

        if (!empty($_POST['download']) && isset($zipPath)) {
            $this->streamDownload($zipPath);
            return;
        }

        $this->redirect('export_ok');
    }

    public function handleImport(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_IMPORT);

        $confirmation = isset($_POST['confirm']) ? sanitize_text_field((string) $_POST['confirm']) : '';
        if ($confirmation !== 'JA LOESCHEN') {
            $this->redirect('import_not_confirmed');
        }

        $file = isset($_POST['backup_file']) ? sanitize_file_name((string) $_POST['backup_file']) : '';
        if ($file === '') {
            $this->redirect('import_no_file');
        }

        $resolved = $this->storage->resolveInside($this->storage->backupsPath(), $file);
        if ($resolved === null) {
            $this->redirect('import_invalid_path');
        }

        try {
            $this->importer->importFromFile((string) $resolved);
        } catch (\Throwable $e) {
            $this->redirect('import_failed');
        }

        $this->redirect('import_ok');
    }

    public function handleDelete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_DELETE);

        $file = isset($_POST['backup_file']) ? sanitize_file_name((string) $_POST['backup_file']) : '';
        if ($file !== '') {
            $resolved = $this->storage->resolveInside($this->storage->backupsPath(), $file);
            if ($resolved !== null && is_file($resolved)) {
                @unlink($resolved);
            }
        }

        $this->redirect('delete_ok');
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            'rhbp_message' => $message,
        ], admin_url('options-general.php')));
        exit;
    }

    private function renderExportCard(): void
    {
        echo '<div class="rhbp-db-card">';
        echo '<h3>' . esc_html__('Backup erstellen', 'rh-blueprint') . '</h3>';
        echo '<p>' . esc_html__('Erstellt ein ZIP mit Datenbank und Manifest. Wird im Ordner rh-blueprint-data/backups/ gespeichert.', 'rh-blueprint') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_EXPORT);
        echo '<input type="hidden" name="action" value="rhbp_db_export" />';

        echo '<label class="rhbp-check"><input type="checkbox" name="include_uploads" value="1" /> ';
        echo esc_html__('Uploads-Ordner einschliessen (große ZIPs möglich)', 'rh-blueprint');
        echo '</label>';

        echo '<div class="rhbp-db-card__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Backup erstellen', 'rh-blueprint') . '</button>';
        echo '<button type="submit" name="download" value="1" class="button">' . esc_html__('Erstellen + Download', 'rh-blueprint') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private function renderImportCard(): void
    {
        $backups = $this->storage->listBackups();

        echo '<div class="rhbp-db-card">';
        echo '<h3>' . esc_html__('Backup wiederherstellen', 'rh-blueprint') . '</h3>';
        echo '<p class="rhbp-db-card__warning">' . esc_html__('Achtung: Die aktuelle Datenbank wird überschrieben. Dieser Vorgang kann nicht rueckgaengig gemacht werden.', 'rh-blueprint') . '</p>';

        if ($backups === []) {
            echo '<p class="rhbp-empty">' . esc_html__('Noch keine Backups vorhanden.', 'rh-blueprint') . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_IMPORT);
        echo '<input type="hidden" name="action" value="rhbp_db_import" />';

        echo '<label>' . esc_html__('Backup auswählen', 'rh-blueprint') . '</label>';
        echo '<select name="backup_file">';
        foreach ($backups as $backup) {
            printf('<option value="%1$s">%1$s</option>', esc_attr($backup));
        }
        echo '</select>';

        echo '<label>' . esc_html__('Zur Bestätigung "JA LOESCHEN" eintippen:', 'rh-blueprint') . '</label>';
        echo '<input type="text" name="confirm" placeholder="JA LOESCHEN" autocomplete="off" />';

        echo '<div class="rhbp-db-card__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Backup wiederherstellen', 'rh-blueprint') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private function renderBackupList(): void
    {
        $backups = $this->storage->listBackups();

        echo '<div class="rhbp-db-card">';
        echo '<h3>' . esc_html__('Vorhandene Backups', 'rh-blueprint') . '</h3>';

        if ($backups === []) {
            echo '<p class="rhbp-empty">' . esc_html__('Keine Backups vorhanden.', 'rh-blueprint') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="rhbp-db-table"><thead><tr>';
        echo '<th>' . esc_html__('Datei', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Größe', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Datum', 'rh-blueprint') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ($backups as $backup) {
            $path = trailingslashit($this->storage->backupsPath()) . $backup;
            $size = is_file($path) ? (int) filesize($path) : 0;
            $mtime = is_file($path) ? (int) filemtime($path) : 0;

            echo '<tr>';
            echo '<td><code>' . esc_html($backup) . '</code></td>';
            echo '<td>' . esc_html(size_format($size, 2) ?: ', ') . '</td>';
            echo '<td>' . esc_html($mtime > 0 ? wp_date('Y-m-d H:i', $mtime) : ', ') . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            wp_nonce_field(self::NONCE_DELETE);
            echo '<input type="hidden" name="action" value="rhbp_db_delete" />';
            echo '<input type="hidden" name="backup_file" value="' . esc_attr($backup) . '" />';
            echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Backup wirklich löschen?\')">' . esc_html__('Löschen', 'rh-blueprint') . '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function streamDownload(string $zipPath): void
    {
        if (!is_readable($zipPath)) {
            wp_die(esc_html__('Backup-Datei nicht lesbar.', 'rh-blueprint'));
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
        header('Content-Length: ' . (string) filesize($zipPath));

        readfile($zipPath);
        exit;
    }
}
