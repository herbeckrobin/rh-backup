<?php

declare(strict_types=1);

namespace RhBackup\Admin;

use RhBackup\Storage\BackupEntry;
use RhBackup\Storage\BackupKind;
use RhBackup\Storage\DriveStore;
use RhBackup\Storage\LocalStore;
use RhBackup\Storage\RestoreRunner;
use RhBackup\Storage\StoreRegistry;
use RhBackup\Storage\TransferJob;
use RhBackup\Storage\TransferRunner;
use RhBlueprint\Core\Settings\SettingsPage;
use RhDbEngine\Exporter;
use RhDbEngine\JobLock;
use RhDbEngine\Storage;

final class DbToolsPage
{
    public const TAB_ID = 'backup';
    public const CAPABILITY = 'manage_options';
    public const NONCE_IMPORT = 'rhbp_db_import';
    public const NONCE_DELETE = 'rhbp_db_delete';
    public const NONCE_DOWNLOAD = 'rhbp_db_download';
    public const NONCE_REFRESH = 'rhbp_backup_refresh';

    /** Gemeinsamer Lock für Export und Wiederherstellung. */
    private const LOCK_NAME = 'db';

    public function __construct(
        private readonly Storage $storage,
        private readonly Exporter $exporter,
        private readonly StoreRegistry $stores,
        private readonly RestoreRunner $restore,
        private readonly TransferRunner $transfer,
    ) {
    }

    /** Anzahl Mediendateien, die der laufende Import nicht schreiben konnte. */
    private int $uploadsFailed = 0;

    public function boot(): void
    {
        add_action('admin_post_rhbp_db_import', [$this, 'handleImport']);
        add_action('admin_post_rhbp_db_download', [$this, 'handleDownload']);
        add_action('admin_post_rhbp_db_delete', [$this, 'handleDelete']);
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('rh-backup/pane', [$this, 'renderPane']);
        add_action('admin_init', [$this, 'maybeRefreshList']);
        add_action('rh-backup/settings_modal', [$this, 'renderModalCards']);
    }

    public function renderInlineMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige einer Status-Meldung nach Redirect, keine zustandsändernde Aktion.
        $message = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';
        if ($message === '') {
            return;
        }

        $map = [
            'import_ok' => ['success', __('Backup erfolgreich wiederhergestellt.', 'rh-backup')],
            'import_failed' => ['error', __('Import fehlgeschlagen.', 'rh-backup')],
            'import_no_safety' => ['error', __('Import abgebrochen: die Sicherungskopie konnte nicht erstellt werden. Die Datenbank ist unverändert. Bitte Plattenplatz und Schreibrechte prüfen, Details stehen im Error-Log.', 'rh-backup')],
            'import_rolled_back' => ['warning', __('Import fehlgeschlagen. Die Datenbank wurde auf den Stand von vor dem Import zurückgesetzt. Details stehen im Error-Log.', 'rh-backup')],
            'import_rollback_failed' => ['error', __('Import fehlgeschlagen und das Zurücksetzen ebenfalls. Die Datenbank ist in einem unvollständigen Zustand. Die Sicherungskopie liegt im Ordner rh-blueprint-data/auto-backups/, der Pfad steht im Error-Log.', 'rh-backup')],
            'import_incomplete_uploads' => ['warning', __('Datenbank wiederhergestellt, aber einzelne Mediendateien konnten nicht geschrieben werden. Details stehen im Error-Log.', 'rh-backup')],
            'import_ok_logged_out' => ['warning', __('Backup wiederhergestellt. Dein Konto existiert im wiederhergestellten Stand nicht, du wirst deshalb abgemeldet.', 'rh-backup')],
            'job_running' => ['warning', __('Es läuft bereits ein Backup- oder Wiederherstellungs-Vorgang. Bitte warten, bis er abgeschlossen ist.', 'rh-backup')],
            'import_not_confirmed' => ['warning', __('Bitte "JA LOESCHEN" eingeben um den Import zu bestätigen.', 'rh-backup')],
            'import_no_file' => ['warning', __('Kein Backup ausgewählt.', 'rh-backup')],
            'import_invalid_path' => ['error', __('Ungültiger Backup-Pfad.', 'rh-backup')],
            'delete_ok' => ['success', __('Backup gelöscht.', 'rh-backup')],
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

    /**
     * Wirft die gemerkte Liste eines entfernten Ablageorts weg.
     *
     * Über die Adresse statt über ein Formular: es ändert nichts, es holt nur neu.
     */
    public function maybeRefreshList(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- direkt darunter geprüft.
        if (! isset($_GET['action']) || $_GET['action'] !== 'rhbp_backup_refresh') {
            return;
        }

        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        check_admin_referer(self::NONCE_REFRESH);
        DriveStore::forgetList();

        wp_safe_redirect(add_query_arg([
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            'sub' => BackupTabs::PANE_RESTORE,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Steuert die Karten dieses Bereichs bei. Aufgeteilt wird in {@see BackupTabs}.
     */
    public function renderPane(string $pane): void
    {
        echo '<div class="rhbp-db-tools">';

        match ($pane) {
            BackupTabs::PANE_RESTORE => $this->renderRestorePane(),
            default => null,
        };

        echo '</div>';
    }

    /**
     * Die vorhandenen Sicherungen und der Weg, eine davon einzuspielen.
     *
     * Erzeugt wird hier nichts: dafür gibt es den Knopf in der Übersicht. Zwei Wege zum
     * selben Ziel, die sich nur im Innenleben unterscheiden, verwirren mehr als sie nützen.
     */
    private function renderRestorePane(): void
    {
        $this->renderBackupList();
        $this->renderImportCard();
    }

    /**
     * Karten für das Einstellungs-Modal der automatischen Sicherung.
     *
     * Der Hinweis auf den ungeschützten Datenordner gehört zur Ablage: es geht darum,
     * wo die Sicherungen liegen und ob dort jemand herankommt.
     */
    public function renderModalCards(string $pane): void
    {
        // Nur wenn die Sicherungen wirklich hier liegen. Zeigen sie nach Google Drive,
        // gibt es im Datenordner nichts mehr zu schützen.
        if ($pane === 'zeitplan' && $this->stores->current() instanceof LocalStore) {
            $this->renderServerHint();
        }
    }

    /**
     * Liefert eine vorhandene Sicherung zum Herunterladen aus.
     */
    public function handleDownload(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-backup'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_DOWNLOAD);

        $ref = isset($_POST['backup_file']) ? $this->sanitizeBackupRef(wp_unslash($_POST['backup_file'])) : '';
        $store = $this->stores->current();
        $eintrag = $ref === '' ? null : $store->find($ref);

        if ($eintrag === null) {
            $this->redirect('import_invalid_path');
        }

        // Liegt die Sicherung auf diesem Server, wird die Datei ausgeliefert. Liegt sie
        // woanders, wird sie durchgereicht: sie erst komplett herzuholen, nur um sie
        // weiterzugeben, würde bei hundert Megabyte am Zeitlimit scheitern.
        if ($store instanceof LocalStore) {
            $pfad = $store->path($ref);
            if ($pfad === null) {
                $this->redirect('import_invalid_path');
            }

            $this->streamDownload($pfad);
        }

        $this->streamRemote($store->open($ref), $eintrag);
    }

    /**
     * Reicht eine Sicherung von einem entfernten Ablageort an den Browser durch.
     */
    private function streamRemote(\RhDbEngine\Archive\ArchiveStream $stream, BackupEntry $eintrag): never
    {
        if (headers_sent()) {
            wp_die(esc_html__('Der Download konnte nicht gestartet werden, es wurde bereits etwas ausgegeben.', 'rh-backup'));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- auf manchen Servern gesperrt.
            @set_time_limit(0);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::headerFileName($eintrag->name) . '"');
        header('Content-Length: ' . $stream->size());
        header('X-Content-Type-Options: nosniff');

        $offset = 0;
        $gesamt = $stream->size();

        while ($offset < $gesamt) {
            $daten = $stream->readAt($offset, 4 * 1024 * 1024);
            if ($daten === '') {
                break;
            }

            echo $daten; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binärdaten eines Archivs.
            flush();
            $offset += strlen($daten);
        }

        $stream->close();
        exit;
    }

    public function handleImport(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-backup'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_IMPORT);

        $confirmation = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
        if ($confirmation !== 'JA LOESCHEN') {
            $this->redirect('import_not_confirmed');
        }

        $ref = isset($_POST['backup_file']) ? $this->sanitizeBackupRef(wp_unslash($_POST['backup_file'])) : '';
        if ($ref === '') {
            $this->redirect('import_no_file');
        }

        $store = $this->stores->current();
        $eintrag = $store->find($ref);

        if ($eintrag === null) {
            $this->redirect('import_invalid_path');
        }

        // Ein Voll-Backup enthält ausführbaren Code und wird von Hand ausgepackt, nicht
        // über das Plugin eingespielt. Sonst käme dieser Code über einen Sync von einem
        // fremden Rechner.
        if ($eintrag->isFullSite()) {
            $this->redirect('import_full_site');
        }

        // Liegt die Sicherung woanders, muss sie erst hierher. Das dauert und läuft
        // deshalb im Hintergrund, mit demselben Vorgang wie ein Umzug.
        if (! $store instanceof LocalStore) {
            try {
                $this->transfer->start(
                    $store->id(),
                    LocalStore::ID,
                    [$eintrag],
                    false,
                    TransferJob::PURPOSE_RESTORE,
                    $this->currentUserLogin()
                );
            } catch (\Throwable $e) {
                $this->redirect('import_failed');
            }

            $this->redirect('restore_started');
        }

        $this->lockOrRedirect();

        $pfad = $this->stores->local()->path($ref);
        if ($pfad === null) {
            $this->redirect('import_invalid_path');
        }

        $ergebnis = $this->restore->restoreFile($pfad, $this->currentUserLogin());

        $this->redirect(RestoreRunner::messageFor($ergebnis));
    }

    /**
     * Login-Name des aktuell angemeldeten Benutzers, für die Anmeldung nach dem Import.
     */
    private function currentUserLogin(): string
    {
        $user = wp_get_current_user();

        return $user instanceof \WP_User ? (string) $user->user_login : '';
    }

    /**
     * Übernimmt den Lauf-Lock oder bricht ab, wenn bereits einer läuft.
     *
     * Die Freigabe hängt am Request-Ende statt an einzelnen Code-Pfaden: die Handler
     * verlassen den Request über wp_safe_redirect + exit, ein finally greift dort nicht.
     */
    private function lockOrRedirect(): void
    {
        if (!JobLock::acquire(self::LOCK_NAME)) {
            $this->redirect('job_running');
        }

        register_shutdown_function(static function (): void {
            JobLock::release(self::LOCK_NAME);
        });
    }

    /**
     * Schreibt einen Fehlschlag ins PHP-Error-Log und gibt ihn an Monitoring weiter.
     *
     * Ohne diese Spur ist ein Fehler auf einer Kundenseite nicht diagnostizierbar: der
     * Nutzer sieht nur eine allgemeine Meldung, und die Ausnahme war bisher verloren.
     */
    private function logError(string $context, \Throwable $e): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnose eines Backup-Fehlschlags auf Kundenseiten, sonst nicht nachvollziehbar.
        error_log(sprintf(
            '[rh-backup] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        /**
         * Anknüpfpunkt für Monitoring (z.B. rh-monitor).
         */
        do_action('rh-backup/error', $context, $e);
    }

    public function handleDelete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-backup'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_DELETE);

        $ref = isset($_POST['backup_file']) ? $this->sanitizeBackupRef(wp_unslash($_POST['backup_file'])) : '';
        if ($ref !== '') {
            $this->stores->current()->delete($ref);
        }

        $this->redirect('delete_ok');
    }

    /**
     * Bereinigt den Verweis auf ein Archiv aus einem Formular.
     *
     * Seit die Sicherungen nach Anlass in Unterordnern liegen, ist der Verweis nicht mehr
     * nur ein Dateiname, sondern kann eine Ebene davor haben. sanitize_file_name() würde
     * den Schrägstrich entfernen und damit jeden Verweis auf einen Unterordner zerstören.
     * Darum jedes Segment einzeln bereinigen, höchstens zwei, und den Rest verwirft
     * ohnehin Storage::resolveInside().
     *
     * @param mixed $raw
     */
    /**
     * Macht einen Dateinamen für den Content-Disposition-Header ungefährlich.
     *
     * Namen aus Google Drive bestimmt nicht nur diese Installation: wer Zugriff auf den
     * Ordner hat, kann sie setzen. Ein Anführungszeichen darin würde die Quotierung im
     * Header aufbrechen, Steuerzeichen hätten dort ohnehin nichts verloren.
     */
    private static function headerFileName(string $name): string
    {
        $sauber = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name) ?? '';
        $sauber = trim($sauber);

        return $sauber === '' ? 'backup.zip' : $sauber;
    }

    private function sanitizeBackupRef($raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $teile = array_values(array_filter(
            explode('/', str_replace('\\', '/', $raw)),
            static fn (string $t): bool => $t !== ''
        ));

        if ($teile === [] || count($teile) > 2) {
            return '';
        }

        $sauber = array_map('sanitize_file_name', $teile);
        foreach ($sauber as $teil) {
            if ($teil === '' || $teil === '.' || $teil === '..') {
                return '';
            }
        }

        return implode('/', $sauber);
    }

    private function redirect(string $message): never
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- die Aktion selbst ist bereits geprüft, das hier steuert nur die Anzeige.
        $pane = isset($_POST[BackupTabs::PARAM]) ? sanitize_key(wp_unslash($_POST[BackupTabs::PARAM])) : '';

        wp_safe_redirect(add_query_arg([
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            BackupTabs::PARAM => isset(BackupTabs::panes()[$pane]) ? $pane : BackupTabs::PANE_ARCHIVES,
            'rhbp_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Weist auf Nginx darauf hin, dass der Backup-Ordner serverseitig gesperrt werden muss.
     *
     * Die Guard-Datei .htaccess wertet Nginx nicht aus. Die Backup-Dateinamen tragen zwar
     * 20 Zufallszeichen und sind praktisch nicht erratbar, aber die zweite Verteidigungslinie
     * fehlt dort, und der Betreiber sollte das wissen.
     */
    private function renderServerHint(): void
    {
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))) : '';
        if ($server === '' || !str_contains($server, 'nginx')) {
            return;
        }

        echo '<div class="rhbp-db-card">';
        echo '<h3>' . esc_html__('Hinweis zum Webserver', 'rh-backup') . '</h3>';
        echo '<p>' . esc_html__('Dieser Server läuft mit Nginx und wertet die mitgelieferte .htaccess nicht aus. Bitte den Datenordner in der Server-Konfiguration sperren:', 'rh-backup') . '</p>';
        echo '<pre><code>location ~* /wp-content/rh-blueprint-data/ { deny all; }</code></pre>';
        echo '</div>';
    }

    private function renderImportCard(): void
    {
        $store = $this->stores->current();
        $eintraege = array_filter($store->list(), static fn (BackupEntry $e): bool => ! $e->isFullSite());

        echo '<div class="rhbp-db-card">';
        echo '<h3>' . esc_html__('Sicherung einspielen', 'rh-backup') . '</h3>';
        echo '<p class="rhbp-db-card__warning">' . esc_html__('Achtung: Die aktuelle Datenbank wird überschrieben. Dieser Vorgang kann nicht rückgängig gemacht werden.', 'rh-backup') . '</p>';

        if ($eintraege === []) {
            echo '<p class="rhbp-empty">' . esc_html__('Es gibt keine Sicherung, die sich einspielen lässt.', 'rh-backup') . '</p>';
            echo '</div>';

            return;
        }

        if (! $store instanceof LocalStore) {
            echo '<p>' . esc_html__('Die Sicherung wird zuerst hierher geholt und dann eingespielt. Das läuft im Hintergrund, du kannst die Seite verlassen.', 'rh-backup') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_IMPORT);
        echo '<input type="hidden" name="action" value="rhbp_db_import" />';

        echo '<label>' . esc_html__('Sicherung auswählen', 'rh-backup') . '</label>';
        echo '<select name="backup_file">';

        // Nach Anlass gruppiert, sonst steht dort eine Reihe gleich aussehender Namen,
        // bei denen niemand weiss, welcher wovon stammt.
        $gruppen = [];
        foreach ($eintraege as $eintrag) {
            $gruppen[$eintrag->kind][] = $eintrag;
        }

        foreach ($gruppen as $kind => $gruppe) {
            printf('<optgroup label="%s">', esc_attr(BackupKind::label((string) $kind)));
            foreach ($gruppe as $eintrag) {
                printf(
                    '<option value="%s">%s (%s)</option>',
                    esc_attr($eintrag->ref),
                    esc_html($eintrag->name),
                    esc_html(size_format($eintrag->size))
                );
            }
            echo '</optgroup>';
        }

        echo '</select>';

        echo '<label>' . esc_html__('Zur Bestätigung "JA LOESCHEN" eintippen:', 'rh-backup') . '</label>';
        echo '<input type="text" name="confirm" placeholder="JA LOESCHEN" autocomplete="off" />';

        echo '<div class="rhbp-db-card__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Sicherung einspielen', 'rh-backup') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private function renderBackupList(): void
    {
        $store = $this->stores->current();
        $eintraege = $store->list();

        echo '<div class="rhbp-db-card">';
        echo '<div class="rhbp-offsite__head">';
        echo '<h3>' . esc_html__('Vorhandene Sicherungen', 'rh-backup') . '</h3>';
        echo '<span>';
        printf('<span class="rhbp-pill">%s</span> ', esc_html($store->label()));

        // Bei einem entfernten Ort wird die Liste kurz gemerkt, sonst hinge jeder
        // Seitenaufbau an einer Anfrage über das Netz. Wer dort selbst etwas geändert
        // hat, kommt damit sofort an den aktuellen Stand.
        if (! $store instanceof LocalStore) {
            printf(
                '<a class="button" href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    add_query_arg([
                        'page' => SettingsPage::MENU_SLUG,
                        'tab' => self::TAB_ID,
                        'sub' => BackupTabs::PANE_RESTORE,
                        'action' => 'rhbp_backup_refresh',
                    ], admin_url('admin.php')),
                    self::NONCE_REFRESH
                )),
                esc_html__('Liste auffrischen', 'rh-backup')
            );
        }
        echo '</span>';
        echo '</div>';

        if (! $store->isReady()) {
            echo '<p class="rhbp-db-card__warning">' . esc_html($store->notReadyReason()) . '</p>';
            echo '</div>';

            return;
        }

        if ($eintraege === []) {
            echo '<p class="rhbp-empty">' . esc_html__('Hier liegt noch keine Sicherung.', 'rh-backup') . '</p>';
            echo '</div>';

            return;
        }

        echo '<table class="rhbp-db-table"><thead><tr>';
        echo '<th>' . esc_html__('Datei', 'rh-backup') . '</th>';
        echo '<th>' . esc_html__('Anlass', 'rh-backup') . '</th>';
        echo '<th>' . esc_html__('Größe', 'rh-backup') . '</th>';
        echo '<th>' . esc_html__('Datum', 'rh-backup') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ($eintraege as $eintrag) {
            echo '<tr>';
            echo '<td><code>' . esc_html($eintrag->name) . '</code>';
            if ($eintrag->isFullSite()) {
                echo '<br><small>' . esc_html__('Komplette Website, wird von Hand ausgepackt', 'rh-backup') . '</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html($eintrag->kindLabel()) . '</td>';
            echo '<td>' . esc_html(size_format($eintrag->size, 2) ?: ', ') . '</td>';
            echo '<td>' . esc_html($eintrag->time > 0 ? wp_date('Y-m-d H:i', $eintrag->time) : ', ') . '</td>';
            echo '<td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            wp_nonce_field(self::NONCE_DOWNLOAD);
            echo '<input type="hidden" name="action" value="rhbp_db_download" />';
            echo '<input type="hidden" name="backup_file" value="' . esc_attr($eintrag->ref) . '" />';
            echo '<button type="submit" class="button">' . esc_html__('Herunterladen', 'rh-backup') . '</button>';
            echo '</form> ';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            wp_nonce_field(self::NONCE_DELETE);
            echo '<input type="hidden" name="action" value="rhbp_db_delete" />';
            echo '<input type="hidden" name="backup_file" value="' . esc_attr($eintrag->ref) . '" />';
            printf(
                '<button type="submit" class="button button-link-delete" onclick="return confirm(%s)">%s</button>',
                esc_attr(wp_json_encode(__('Diese Sicherung endgültig löschen?', 'rh-backup'))),
                esc_html__('Löschen', 'rh-backup')
            );
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
            wp_die(esc_html__('Backup-Datei nicht lesbar.', 'rh-backup'));
        }

        // Hat schon jemand Ausgabe gesendet, wäre das heruntergeladene ZIP beschädigt,
        // und das fällt erst beim Wiederherstellen auf. Lieber ehrlich abbrechen.
        if (headers_sent($file, $line)) {
            $this->logError(
                sprintf('Download abgebrochen, Ausgabe begann bereits in %s:%d', $file, $line),
                new \RuntimeException('headers already sent')
            );
            wp_die(esc_html__('Download nicht möglich, ein anderes Plugin hat bereits Ausgabe gesendet. Details stehen im Error-Log.', 'rh-backup'));
        }

        // Ein aktiver Output-Buffer (verbreitet bei Caching-Plugins) würde die komplette
        // Datei im Speicher puffern. Gemessen: 40-MB-Datei bedeutet 40 MB RAM, bei
        // Backups im Gigabyte-Bereich also ein Speicherlimit-Abbruch.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . self::headerFileName(basename($zipPath)) . '"');
        header('Content-Length: ' . (string) filesize($zipPath));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming großer Backup-Dateien, WP_Filesystem lädt komplette Dateien in den RAM und ist auf Shared Hosting untauglich.
        readfile($zipPath);
        exit;
    }
}
