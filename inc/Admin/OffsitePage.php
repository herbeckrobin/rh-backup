<?php

declare(strict_types=1);

namespace RhBackup\Admin;

use RhBlueprint\Core\Admin\Assets;
use RhBlueprint\Core\Admin\Guard;
use RhBackup\Cron\CronHealth;
use RhBackup\Cron\PingEndpoint;
use RhBackup\Offsite\Connection;
use RhBackup\Offsite\GoogleDrive;
use RhBackup\Offsite\Scheduler;
use RhBackup\Offsite\Settings;
use RhBackup\Offsite\UploadJob;
use RhBackup\Offsite\UploadRunner;
use RhBackup\Storage\BackupEntry;
use RhBackup\Storage\BackupStore;
use RhBackup\Storage\LocalStore;
use RhBackup\Storage\StoreRegistry;
use RhBackup\Storage\TransferJob;
use RhBackup\Storage\TransferRunner;

/**
 * Oberfläche für das Offsite-Backup nach Google Drive.
 *
 * Sitzt unter den vorhandenen Backup-Werkzeugen im selben Tab, im Reihen-Look der
 * übrigen Module. Aufbau in der Reihenfolge, in der man es einmal einrichtet:
 * Verbindung, dann Zeitplan und Umfang, dann der Betrieb mit Status und manuellem Lauf.
 *
 * Die Zugangsdaten der Anwendung kommen bewusst NICHT hier vor. Sie gehen den Betreiber
 * der Website nichts an, er verbindet nur sein eigenes Google-Konto. Woher sie stammen:
 * {@see \RhBackup\Offsite\Credentials}.
 *
 * Der Verbindungs-Ablauf ist der Geräte-Ablauf von Google: die Seite zeigt einen kurzen
 * Code, der Kunde tippt ihn auf google.com/device ein, die Seite fragt im Hintergrund
 * nach, bis die Freigabe da ist. Kein Rücksprung auf eine fremde Adresse.
 */
final class OffsitePage
{
    /** Teilt sich den Tab mit den übrigen Backup-Werkzeugen, kein eigener Menüpunkt. */
    public const TAB_ID = 'backup';
    public const CAPABILITY = 'manage_options';

    private const NONCE_CONNECT = 'rhbackup_offsite_connect';
    private const NONCE_DISCONNECT = 'rhbackup_offsite_disconnect';
    private const NONCE_SAVE = 'rhbackup_offsite_save';
    private const NONCE_RUN = 'rhbackup_offsite_run';
    public const NONCE_AJAX = 'rhbackup_offsite_ajax';
    private const NONCE_PING = 'rhbackup_ping_regenerate';
    private const NONCE_MODE = 'rhbackup_offsite_mode';
    private const NONCE_PURGE = 'rhbackup_offsite_purge_local';
    private const NONCE_MOVE = 'rhbackup_offsite_move';

    /** Kennung des Einstellungs-Modals der automatischen Sicherung. */
    private const MODAL_ID = 'rhbp-modal-backup-auto';

    /** Kennung des Modals hinter dem Zahnrad des Ablageorts. */
    private const MODAL_STORE_ID = 'rhbp-modal-backup-store';

    /** Läuft der Geräte-Ablauf gerade, liegt der Code hier. */
    private const PENDING_TRANSIENT = 'rhbackup_offsite_pending';

    public function __construct(
        private readonly Connection $connection,
        private readonly GoogleDrive $drive,
        private readonly UploadRunner $runner,
        private readonly StoreRegistry $stores,
        private readonly TransferRunner $transfer,
    ) {
    }

    public function boot(): void
    {
        add_action('admin_post_rhbackup_offsite_connect', [$this, 'handleConnect']);
        add_action('admin_post_rhbackup_offsite_disconnect', [$this, 'handleDisconnect']);
        add_action('admin_post_rhbackup_offsite_save', [$this, 'handleSave']);
        add_action('admin_post_rhbackup_offsite_run', [$this, 'handleRun']);
        add_action('admin_post_rhbackup_ping_regenerate', [$this, 'handleRegeneratePing']);
        add_action('admin_post_rhbackup_offsite_mode', [$this, 'handleMode']);
        add_action('admin_post_rhbackup_offsite_purge_local', [$this, 'handlePurgeLocal']);
        add_action('admin_post_rhbackup_offsite_move', [$this, 'handleMove']);
        add_action('wp_ajax_rhbackup_offsite_poll', [$this, 'ajaxPoll']);
        add_action('wp_ajax_rhbackup_offsite_status', [$this, 'ajaxStatus']);

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderMessage']);
        add_action('rh-backup/pane', [$this, 'renderPane']);
    }

    /**
     * Lädt die wenigen Regeln, die der Core noch nicht mitbringt.
     */
    public function enqueueAssets(): void
    {
        if (! Assets::onSettings()) {
            return;
        }

        $file = RHBACKUP_PLUGIN_DIR . 'assets/offsite.css';

        wp_enqueue_style(
            'rh-backup-offsite',
            plugins_url('assets/offsite.css', RHBACKUP_PLUGIN_FILE),
            ['rh-blueprint-settings'],
            is_readable($file) ? (string) filemtime($file) : RHBACKUP_VERSION
        );
    }

    // ============================================================
    // Ausgabe
    // ============================================================

    public function renderMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige nach Redirect.
        $key = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';
        if ($key === '') {
            return;
        }

        $map = [
            'offsite_connected' => ['success', __('Google Drive ist verbunden.', 'rh-backup')],
            'offsite_disconnected' => ['success', __('Die Verbindung wurde getrennt. Den Zugriff kannst du zusätzlich im Google-Konto unter "Drittanbieter-Apps" entziehen.', 'rh-backup')],
            'offsite_saved' => ['success', __('Einstellungen gespeichert.', 'rh-backup')],
            'offsite_started' => ['success', __('Die Sicherung läuft. Du kannst die Seite verlassen, sie läuft im Hintergrund weiter.', 'rh-backup')],
            'offsite_pending' => ['info', __('Bitte den Code im Google-Konto bestätigen.', 'rh-backup')],
            'offsite_ping_regenerated' => ['success', __('Das Merkmal wurde neu erzeugt. Der bisherige Befehl funktioniert ab jetzt nicht mehr, bitte den neuen hinterlegen.', 'rh-backup')],
            'offsite_mode_saved' => ['success', __('Gespeichert. Die nächste Sicherung landet am neuen Ort.', 'rh-backup')],
            'offsite_purged' => ['success', __('Die Sicherungen am alten Ort wurden entfernt.', 'rh-backup')],
            'offsite_move_started' => ['success', __('Der Umzug läuft. Du kannst die Seite verlassen, er läuft im Hintergrund weiter.', 'rh-backup')],
        ];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige nach Redirect.
        $detail = isset($_GET['rhbp_detail']) ? sanitize_text_field(wp_unslash($_GET['rhbp_detail'])) : '';

        if ($key === 'offsite_error') {
            printf(
                '<div class="rhbp-callout rhbp-callout--err"><p>%s</p></div>',
                esc_html($detail !== '' ? $detail : __('Es ist ein Fehler aufgetreten.', 'rh-backup'))
            );

            return;
        }

        if (! isset($map[$key])) {
            return;
        }

        [$type, $text] = $map[$key];
        printf(
            '<div class="rhbp-callout rhbp-callout--%1$s"><p>%2$s%3$s</p></div>',
            esc_attr($type === 'success' ? 'success' : 'info'),
            esc_html($text),
            $detail !== '' ? ' ' . esc_html($detail) : ''
        );
    }

    /**
     * Steuert die Karten dieses Bereichs bei.
     *
     * Was wohin gehört, entscheidet {@see BackupTabs}: hier steht nur, welche Karte zu
     * welchem Bereich passt.
     */
    public function renderPane(string $pane): void
    {
        if ($pane !== BackupTabs::PANE_OVERVIEW) {
            return;
        }

        echo '<div class="rhbp-db-tools rhbp-offsite">';

        // Der Ablageort steht ganz oben: er bestimmt alles darunter.
        $this->renderStoreSwitch();
        $this->renderTransferCard();
        $this->renderProgressCard();
        $this->renderManualCard();
        $this->renderAutomaticCard();

        echo '</div>';

        // Die Modale stehen ausserhalb der Karten, sonst erben sie deren Platzierung.
        $this->renderStoreModal();
        $this->renderSettingsModal();
    }

    private function renderConnectionCard(): void
    {
        $connected = $this->connection->isConnected();
        $pending = get_transient(self::PENDING_TRANSIENT);

        echo '<div class="rhbp-db-card">';
        echo '<div class="rhbp-offsite__head">';
        echo '<h2>' . esc_html__('Verbindung', 'rh-backup') . '</h2>';
        echo $connected
            ? '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('Verbunden', 'rh-backup') . '</span>'
            : '<span class="rhbp-pill">' . esc_html__('Nicht verbunden', 'rh-backup') . '</span>';
        echo '</div>';

        if ($connected) {
            $account = $this->connection->account();

            echo '<div class="rhbp-offsite__stats">';
            if ($account !== '') {
                echo '<div class="rhbp-offsite__stat">';
                echo '<span class="rhbp-offsite__stat-label">' . esc_html__('Konto', 'rh-backup') . '</span>';
                echo '<span class="rhbp-offsite__stat-value">' . esc_html($account) . '</span>';
                echo '</div>';
            }
            echo '<div class="rhbp-offsite__stat">';
            echo '<span class="rhbp-offsite__stat-label">' . esc_html__('Ordner', 'rh-backup') . '</span>';
            echo '<span class="rhbp-offsite__stat-value">' . esc_html(Settings::folderName() . '/' . Settings::siteFolderName()) . '</span>';
            echo '</div>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-db-card__actions">';
            wp_nonce_field(self::NONCE_DISCONNECT);
            echo '<input type="hidden" name="action" value="rhbackup_offsite_disconnect" />';
            printf(
                '<button type="submit" class="rhbp-btn rhbp-btn--danger" onclick="return confirm(%s)">%s</button>',
                "'" . esc_js(__('Verbindung wirklich trennen? Vorhandene Sicherungen in Drive bleiben erhalten.', 'rh-backup')) . "'",
                esc_html__('Verbindung trennen', 'rh-backup')
            );
            echo '</form>';
            echo '</div>';

            return;
        }

        if (is_array($pending) && ! empty($pending['user_code'])) {
            $this->renderPendingCode($pending);
            echo '</div>';

            return;
        }

        echo '<p>' . esc_html__('Zum Verbinden zeigt dir die Seite einen kurzen Code. Den gibst du in deinem Google-Konto ein, danach läuft alles automatisch.', 'rh-backup') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-db-card__actions">';
        wp_nonce_field(self::NONCE_CONNECT);
        echo '<input type="hidden" name="action" value="rhbackup_offsite_connect" />';
        echo '<button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Mit Google Drive verbinden', 'rh-backup') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function renderPendingCode(array $pending): void
    {
        $url = (string) ($pending['verification_url'] ?? 'https://www.google.com/device');
        $code = (string) ($pending['user_code'] ?? '');

        echo '<ol class="rhbp-offsite__steps">';
        printf(
            '<li>%s <a href="%s" target="_blank" rel="noopener" class="rhbp-extlink">%s</a></li>',
            esc_html__('Öffne diese Adresse, gern am Handy:', 'rh-backup'),
            esc_url($url),
            esc_html($url)
        );
        echo '<li>' . esc_html__('Gib dort diesen Code ein und bestätige den Zugriff:', 'rh-backup') . '</li>';
        echo '</ol>';

        printf('<div class="rhbp-offsite__code">%s</div>', esc_html($code));

        echo '<p class="rhbp-offsite__hint">' . esc_html__('Diese Seite merkt von selbst, sobald du bestätigt hast.', 'rh-backup') . '</p>';

        // Polling: fragt im Hintergrund nach, ob die Freigabe erteilt wurde.
        $this->printPollingScript();
    }

    /**
     * Der Schalter für den Ablageort, ganz oben.
     *
     * Er ist die Wurzel: er bestimmt, wohin jede Sicherung geht, egal ob von Hand oder
     * planmässig, was in der Liste steht und woher wiederhergestellt wird. Es gibt immer
     * genau einen Ort, an dem die Sicherungen liegen.
     */
    private function renderStoreSwitch(): void
    {
        $aktuell = $this->stores->current();
        $laufend = TransferJob::load();
        $umzugLaeuft = $laufend !== null && ! $laufend->isFinished();

        echo '<div class="rhbp-db-card">';
        echo '<div class="rhbp-offsite__head">';
        echo '<h2>' . esc_html__('Wo die Sicherungen liegen', 'rh-backup') . '</h2>';

        // Das Zahnrad gehört zum Ort, nicht zur automatischen Sicherung: das Google-Konto
        // wird auch gebraucht, wenn man ausschliesslich von Hand sichert.
        if ($aktuell->id() === LocalStore::ID) {
            echo '';
        } else {
            printf(
                '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-open="%s" title="%s" aria-label="%s">%s</button>',
                esc_attr(self::MODAL_STORE_ID),
                esc_attr__('Einstellungen des Ablageorts', 'rh-backup'),
                esc_attr__('Einstellungen des Ablageorts', 'rh-backup'),
                $this->gearIcon()
            );
        }
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_MODE);
        echo '<input type="hidden" name="action" value="rhbackup_offsite_mode" />';

        echo '<div class="rhbp-option-grid">';

        foreach ($this->stores->all() as $id => $store) {
            $bereit = $store->isReady();

            printf(
                '<label class="rhbp-choice"><input type="radio" name="mode" value="%s" %s %s />'
                    . '<span class="rhbp-choice__title">%s</span>'
                    . '<span class="rhbp-choice__desc">%s</span></label>',
                esc_attr($id),
                checked($aktuell->id(), $id, false),
                disabled(! $bereit || $umzugLaeuft, true, false),
                esc_html($store->label()),
                esc_html($bereit ? $this->storeHint($store) : $store->notReadyReason())
            );
        }

        echo '</div>';

        echo '<div class="rhbp-db-card__actions">';
        printf(
            '<button type="submit" class="button button-primary" %s>%s</button>',
            disabled($umzugLaeuft, true, false),
            esc_html__('Übernehmen', 'rh-backup')
        );
        echo '</div>';
        echo '</form>';

        $this->renderStrays($umzugLaeuft);

        echo '</div>';
    }

    /**
     * Was einen Ablageort ausmacht, in einem Satz.
     */
    private function storeHint(BackupStore $store): string
    {
        return $store instanceof LocalStore
            ? __('Schnell erreichbar. Fällt der Server aus, sind die Sicherungen mit weg.', 'rh-backup')
            : __('Unabhängig von diesem Server. Braucht beim Wiederherstellen etwas länger.', 'rh-backup');
    }

    /**
     * Sicherungen, die noch am alten Ort liegen.
     *
     * Sie verschwinden nicht von selbst. Der Betreiber entscheidet: mitnehmen oder weg.
     * Ein stilles Löschen gibt es nicht, und ein stilles Liegenlassen auch nicht, sonst
     * wären sie unauffindbar.
     */
    private function renderStrays(bool $umzugLaeuft): void
    {
        if ($umzugLaeuft) {
            return;
        }

        $verstreut = $this->stores->strays();
        if ($verstreut === []) {
            return;
        }

        $ziel = $this->stores->current();

        foreach ($verstreut as $id => $info) {
            echo '<div class="rhbp-callout rhbp-callout--warn"><p>';
            printf(
                esc_html(
                    /* translators: %1$s: store label, %2$d: number, %3$s: size */
                    _n(
                        '%1$s liegt noch %2$d Sicherung (%3$s). Sie gehört nicht mehr zum gewählten Ablageort.',
                        '%1$s liegen noch %2$d Sicherungen (%3$s). Sie gehören nicht mehr zum gewählten Ablageort.',
                        (int) $info['count'],
                        'rh-backup'
                    )
                ),
                esc_html($info['store']->label()),
                (int) $info['count'],
                esc_html(size_format((int) $info['bytes']))
            );
            echo '</p></div>';

            echo '<div class="rhbp-db-card__actions">';

            printf(
                '<form method="post" action="%s" style="display:inline">',
                esc_url(admin_url('admin-post.php'))
            );
            wp_nonce_field(self::NONCE_MOVE);
            echo '<input type="hidden" name="action" value="rhbackup_offsite_move" />';
            echo '<input type="hidden" name="from" value="' . esc_attr($id) . '" />';
            // "Hierher" statt der Bezeichnung des Ziels: die Bezeichnungen sind ganze
            // Sätze ("Auf diesem Server") und ergeben eingesetzt keinen brauchbaren Text.
            printf(
                '<button type="submit" class="button button-primary">%s</button>',
                esc_html__('Hierher umziehen', 'rh-backup')
            );
            echo '</form> ';

            printf(
                '<form method="post" action="%s" style="display:inline">',
                esc_url(admin_url('admin-post.php'))
            );
            wp_nonce_field(self::NONCE_PURGE);
            echo '<input type="hidden" name="action" value="rhbackup_offsite_purge_local" />';
            echo '<input type="hidden" name="from" value="' . esc_attr($id) . '" />';
            printf(
                '<button type="submit" class="button button-link-delete" onclick="return confirm(%s)">%s</button>',
                esc_attr(wp_json_encode(sprintf(
                    /* translators: %1$d: number, %2$s: size */
                    _n(
                        '%1$d Sicherung (%2$s) endgültig löschen? Das lässt sich nicht rückgängig machen.',
                        '%1$d Sicherungen (%2$s) endgültig löschen? Das lässt sich nicht rückgängig machen.',
                        (int) $info['count'],
                        'rh-backup'
                    ),
                    (int) $info['count'],
                    size_format((int) $info['bytes'])
                ))),
                esc_html__('Endgültig löschen', 'rh-backup')
            );
            echo '</form>';

            echo '</div>';
        }
    }

    /**
     * Fortschritt eines laufenden Umzugs.
     */
    private function renderTransferCard(): void
    {
        $job = TransferJob::load();
        if ($job === null || $job->isFinished()) {
            return;
        }

        echo '<div class="rhbp-db-card">';
        echo '<h2>' . esc_html__('Umzug läuft', 'rh-backup') . '</h2>';
        printf(
            '<div class="rhbp-offsite__bar"><span style="width:%d%%"></span></div>',
            $job->progressPercent()
        );
        printf(
            '<p class="rhbp-offsite__progress-text">%s</p>',
            esc_html($job->message)
        );
        echo '<p class="rhbp-offsite__hint">' . esc_html__('Läuft im Hintergrund weiter, du kannst die Seite verlassen. Die Seite zeigt den Stand beim Neuladen.', 'rh-backup') . '</p>';
        echo '</div>';
    }

    private function renderScheduleCard(): void
    {
        echo '<div class="rhbp-db-card">';
        echo '<h2>' . esc_html__('Zeitplan und Umfang', 'rh-backup') . '</h2>';
        echo '<p>' . esc_html__('Wie oft gesichert wird und wie viele Kopien in Drive liegen bleiben.', 'rh-backup') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_SAVE);
        echo '<input type="hidden" name="action" value="rhbackup_offsite_save" />';

        // Zwei kurze Felder nebeneinander, die Textfelder darunter in voller Breite.
        echo '<div class="rhbp-offsite__row">';

        echo '<div class="rhbp-offsite__col">';
        echo '<label for="rhbp-offsite-interval">' . esc_html__('Wie oft', 'rh-backup') . '</label>';
        echo '<select id="rhbp-offsite-interval" name="interval">';
        foreach (Settings::intervals() as $key => $definition) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($key),
                selected(Settings::interval(), $key, false),
                esc_html($definition['label'])
            );
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="rhbp-offsite__col">';
        echo '<label for="rhbp-offsite-keep">' . esc_html__('Kopien behalten', 'rh-backup') . '</label>';
        printf(
            '<input type="number" id="rhbp-offsite-keep" name="keep_copies" value="%d" min="1" max="365" />',
            Settings::keepCopies()
        );
        echo '</div>';

        echo '</div>';
        echo '<p class="rhbp-offsite__hint">' . esc_html__('Ältere Kopien werden erst gelöscht, nachdem die neue vollständig in Drive angekommen ist.', 'rh-backup') . '</p>';

        echo '<label for="rhbp-offsite-folder">' . esc_html__('Ordner in Google Drive', 'rh-backup') . '</label>';
        printf(
            '<input type="text" id="rhbp-offsite-folder" name="folder_name" value="%s" />',
            esc_attr(Settings::folderName())
        );
        echo '<p class="rhbp-offsite__hint">' . sprintf(
            /* translators: %s: subfolder name */
            esc_html__('Darin legt die Anwendung je Website einen Unterordner an, hier %s.', 'rh-backup'),
            '<code>' . esc_html(Settings::siteFolderName()) . '</code>'
        ) . '</p>';

        echo '<label for="rhbp-offsite-mail">' . esc_html__('Benachrichtigung bei Fehlschlag an', 'rh-backup') . '</label>';
        printf(
            '<input type="email" id="rhbp-offsite-mail" name="notify_email" value="%s" placeholder="%s" />',
            esc_attr((string) rhbp_setting(Settings::GROUP, Settings::NOTIFY_EMAIL, '')),
            esc_attr((string) get_option('admin_email', ''))
        );
        echo '<p class="rhbp-offsite__hint">' . esc_html__('Leer bedeutet: an die Administrator-Adresse der Website.', 'rh-backup') . '</p>';

        echo '<label>' . esc_html__('Was gesichert wird', 'rh-backup') . '</label>';
        echo '<div class="rhbp-option-grid">';

        foreach (Settings::scopes() as $key => $definition) {
            printf(
                '<label class="rhbp-choice"><input type="radio" name="scope" value="%s" %s />'
                    . '<span class="rhbp-choice__title">%s</span>'
                    . '<span class="rhbp-choice__desc">%s</span></label>',
                esc_attr($key),
                checked(Settings::scope(), $key, false),
                esc_html($definition['label']),
                esc_html($definition['hint'])
            );
        }

        echo '</div>';

        // Eigenes Häkchen, nicht Teil des Umfangs: die Datei enthält die Zugangsdaten
        // zur Datenbank und die Sicherheitsschlüssel im Klartext. Wer sie mitsichert,
        // legt sie damit auch dort ab, wo die Sicherung landet.
        printf(
            '<label class="rhbp-check"><input type="checkbox" name="include_config" value="1" %s /> %s</label>',
            checked(Settings::includeConfig(), true, false),
            esc_html__('wp-config.php mitsichern', 'rh-backup')
        );
        echo '<p class="rhbp-offsite__hint">';
        echo esc_html__('Nur bei einer Sicherung der kompletten Website. Die Datei enthält das Datenbank-Passwort und die Sicherheitsschlüssel im Klartext, beim Auspacken darf sie nicht blind über die vorhandene geschrieben werden.', 'rh-backup');
        echo '</p>';

        echo '<div class="rhbp-db-card__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Speichern', 'rh-backup') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Der Fortschritt eines laufenden Vorgangs, egal wer ihn angestossen hat.
     *
     * Steht ganz oben und ist sonst nicht da. Wer nachsieht, während etwas läuft, will
     * das als erstes wissen und nicht zwischen zwei Karten danach suchen.
     */
    private function renderProgressCard(): void
    {
        $job = UploadJob::load();
        $running = $job !== null && ! $job->isFinished();

        printf(
            '<div class="rhbp-db-card rhbp-offsite__running" data-rhbp-offsite-status data-nonce="%s" data-active="%s"%s>'
                . '<h2>%s</h2>'
                . '<div class="rhbp-offsite__bar"><span data-rhbp-offsite-bar style="width:0%%"></span></div>'
                . '<p class="rhbp-offsite__progress-text" data-rhbp-offsite-text></p>'
                . '</div>',
            esc_attr(wp_create_nonce(self::NONCE_AJAX)),
            esc_attr($running ? '1' : '0'),
            $running ? '' : ' hidden',
            esc_html__('Sicherung läuft', 'rh-backup')
        );

        $this->printStatusScript();
    }

    /**
     * Eine Sicherung von Hand. Ein Knopf, mehr braucht es nicht.
     *
     * Was gesichert wird, steht daneben statt als Auswahl davor: der Umfang gehört zu den
     * Einstellungen, und wer ihn ändern will, tut das dort einmal statt bei jedem Lauf.
     */
    private function renderManualCard(): void
    {
        $job = UploadJob::load();
        $running = $job !== null && ! $job->isFinished();
        $bereit = Settings::mode() === Settings::MODE_LOCAL || $this->connection->isConnected();

        echo '<div class="rhbp-db-card">';
        echo '<h2>' . esc_html__('Von Hand sichern', 'rh-backup') . '</h2>';

        printf(
            '<p>%s <strong>%s</strong>%s</p>',
            esc_html__('Erstellt sofort eine Sicherung.', 'rh-backup'),
            esc_html(Settings::scopeLabel()),
            esc_html(Settings::mode() === Settings::MODE_DRIVE
                ? __(', abgelegt in Google Drive.', 'rh-backup')
                : __(', abgelegt auf diesem Server.', 'rh-backup'))
        );

        if (! $bereit) {
            echo '<p class="rhbp-db-card__warning">';
            echo esc_html__('Vorher muss eingestellt werden, wohin gesichert werden soll. Das steht beim Zahnrad der automatischen Sicherung.', 'rh-backup');
            echo '</p>';
            echo '</div>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-db-card__actions">';
        wp_nonce_field(self::NONCE_RUN);
        echo '<input type="hidden" name="action" value="rhbackup_offsite_run" />';
        printf(
            '<button type="submit" class="button button-primary" %s>%s</button>',
            disabled($running, true, false),
            esc_html__('Jetzt sichern', 'rh-backup')
        );
        echo '</form>';

        echo '</div>';
    }

    /**
     * Die automatische Sicherung: wann zuletzt, wann als nächstes, und das Zahnrad.
     */
    private function renderAutomaticCard(): void
    {
        $last = get_option(Settings::OPTION_LAST_RUN);
        $next = Scheduler::nextRun();
        $probleme = CronHealth::problems();

        echo '<div class="rhbp-db-card">';

        echo '<div class="rhbp-offsite__head">';
        echo '<h2>' . esc_html__('Automatische Sicherung', 'rh-backup') . '</h2>';
        printf(
            '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-open="%s" title="%s" aria-label="%s">%s</button>',
            esc_attr(self::MODAL_ID),
            esc_attr__('Einstellungen', 'rh-backup'),
            esc_attr__('Einstellungen der automatischen Sicherung', 'rh-backup'),
            $this->gearIcon()
        );
        echo '</div>';

        echo '<div class="rhbp-offsite__stats">';

        echo '<div class="rhbp-offsite__stat">';
        echo '<span class="rhbp-offsite__stat-label">' . esc_html__('Zuletzt', 'rh-backup') . '</span>';
        echo '<span class="rhbp-offsite__stat-value">';
        if (is_array($last) && isset($last['time'])) {
            $ok = ! empty($last['success']);
            echo esc_html(wp_date('d.m.Y H:i', (int) $last['time']));
            echo $ok
                ? ' <span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('erfolgreich', 'rh-backup') . '</span>'
                : ' <span class="rhbp-pill rhbp-pill--err">' . esc_html__('fehlgeschlagen', 'rh-backup') . '</span>';
            if ($ok) {
                printf(
                    '<small>%s</small>',
                    esc_html(sprintf(
                        /* translators: %1$s: size, %2$s: duration */
                        __('%1$s in %2$s', 'rh-backup'),
                        size_format((int) ($last['size'] ?? 0)),
                        self::formatDuration((int) ($last['duration'] ?? 0))
                    ))
                );
            } elseif (! empty($last['message'])) {
                echo '<small>' . esc_html((string) $last['message']) . '</small>';
            }
        } else {
            echo esc_html__('noch keine', 'rh-backup');
        }
        echo '</span></div>';

        echo '<div class="rhbp-offsite__stat">';
        echo '<span class="rhbp-offsite__stat-label">' . esc_html__('Als nächstes', 'rh-backup') . '</span>';
        echo '<span class="rhbp-offsite__stat-value">';
        if ($next !== null) {
            echo esc_html(wp_date('d.m.Y H:i', $next));
            echo '<small>' . esc_html(Settings::intervalLabel() . ', ' . Settings::scopeLabel()) . '</small>';
        } else {
            echo esc_html__('nicht geplant', 'rh-backup');
        }
        echo '</span></div>';

        echo '</div>';

        // Läuft die Zeitsteuerung nicht, gehört das hierher und nicht in einen eigenen
        // Bereich: es betrifft genau diese Karte.
        if ($probleme !== []) {
            echo '<div class="rhbp-callout rhbp-callout--warn"><p>';
            echo esc_html__('Die automatische Sicherung läuft möglicherweise nicht. Beim Zahnrad steht, was zu tun ist.', 'rh-backup');
            echo '</p></div>';
        }

        echo '</div>';
    }

    /**
     * Eine Dauer in Sekunden als lesbarer Text.
     *
     * Nicht über human_time_diff: das nimmt bei einer Null die aktuelle Zeit als zweiten
     * Wert und meldet für einen Lauf, der unter einer Sekunde durchlief, ein halbes
     * Jahrhundert.
     */
    private static function formatDuration(int $sekunden): string
    {
        if ($sekunden < 1) {
            return __('unter einer Sekunde', 'rh-backup');
        }

        if ($sekunden < 60) {
            /* translators: %d: seconds */
            return sprintf(_n('%d Sekunde', '%d Sekunden', $sekunden, 'rh-backup'), $sekunden);
        }

        $minuten = (int) round($sekunden / 60);
        if ($minuten < 60) {
            /* translators: %d: minutes */
            return sprintf(_n('%d Minute', '%d Minuten', $minuten, 'rh-backup'), $minuten);
        }

        $stunden = (int) round($minuten / 60);

        /* translators: %d: hours */
        return sprintf(_n('%d Stunde', '%d Stunden', $stunden, 'rh-backup'), $stunden);
    }

    /**
     * Das Zahnrad-Symbol. Bewusst inline und nicht als Datei: es ist ein einziges Symbol.
     */
    private function gearIcon(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 '
            . '1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 '
            . '0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 '
            . '0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 '
            . '1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 '
            . '2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 '
            . '0-1.51 1z"/></svg>';
    }

    /**
     * Konto und Ordner des Ablageorts, hinter dem Zahnrad am Schalter.
     *
     * Nur was zum Ort selbst gehört. Der Ort wird nicht hier gewählt, sondern mit dem
     * Schalter davor: eine Entscheidung, die alles bestimmt, gehört nicht in ein Fenster,
     * das man erst öffnen muss.
     */
    private function renderStoreModal(): void
    {
        if ($this->stores->current() instanceof LocalStore) {
            return;
        }

        printf(
            '<div class="rhbp-modal-backdrop" id="%s" data-rhbp-modal-backdrop>',
            esc_attr(self::MODAL_STORE_ID)
        );
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true" aria-label="'
            . esc_attr__('Einstellungen des Ablageorts', 'rh-backup') . '">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l"><div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html__('Google Drive', 'rh-backup') . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html__('Welches Konto die Sicherungen aufnimmt und in welchem Ordner sie landen.', 'rh-backup') . '</p>';
        echo '</div></div>';
        printf(
            '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="%s">&times;</button>',
            esc_attr__('Schließen', 'rh-backup')
        );
        echo '</div>';

        echo '<div class="rhbp-modal__body">';
        if (Settings::hasOAuthClient()) {
            $this->renderConnectionCard();
        } else {
            echo '<div class="rhbp-db-card"><p class="rhbp-db-card__warning">';
            echo esc_html__('Die Anbindung an Google Drive ist für diese Installation nicht eingerichtet. Bitte wende dich an deinen Betreuer.', 'rh-backup');
            echo '</p></div>';
        }
        echo '</div>';

        echo '</div></div>';
    }

    /**
     * Alle Einstellungen der automatischen Sicherung, hinter dem Zahnrad.
     *
     * Drei Bereiche, weil sie drei verschiedene Fragen beantworten: wohin gesichert wird,
     * wann und was, und ob der Zeitplan überhaupt ausgeführt wird. Jeder Bereich hat sein
     * eigenes Formular mit eigenem Speichern-Knopf, statt einem gemeinsamen unten: die
     * Aktionen sind verschieden, das Verbinden mit Google speichert keine Felder.
     */
    private function renderSettingsModal(): void
    {
        $probleme = CronHealth::problems();

        printf(
            '<div class="rhbp-modal-backdrop" id="%s" data-rhbp-modal-backdrop>',
            esc_attr(self::MODAL_ID)
        );
        echo '<div class="rhbp-modal" role="dialog" aria-modal="true" aria-label="'
            . esc_attr__('Einstellungen der automatischen Sicherung', 'rh-backup') . '">';

        echo '<div class="rhbp-modal__head">';
        echo '<div class="rhbp-modal__head-l"><div>';
        echo '<h3 class="rhbp-modal__title">' . esc_html__('Automatische Sicherung', 'rh-backup') . '</h3>';
        echo '<p class="rhbp-modal__sub">' . esc_html__('Wohin gesichert wird, wie oft, und ob der Zeitplan wirklich läuft.', 'rh-backup') . '</p>';
        echo '</div></div>';
        printf(
            '<button type="button" class="rhbp-btn rhbp-btn--ghost rhbp-btn--icon" data-rhbp-modal-close aria-label="%s">&times;</button>',
            esc_attr__('Schließen', 'rh-backup')
        );
        echo '</div>';

        echo '<div class="rhbp-modal__body">';

        echo '<div class="rhbp-subtabs">';
        printf(
            '<button type="button" class="rhbp-subtab is-active" data-rhbp-subtab="zeitplan">%s</button>',
            esc_html__('Zeitplan und Umfang', 'rh-backup')
        );
        printf(
            '<button type="button" class="rhbp-subtab" data-rhbp-subtab="verlaesslich">%s%s</button>',
            esc_html__('Verlässlichkeit', 'rh-backup'),
            $probleme === [] ? '' : ' <span class="rhbp-pill rhbp-pill--err">!</span>'
        );
        echo '</div>';

        echo '<div class="rhbp-tabpane is-active" data-rhbp-pane="zeitplan">';
        $this->renderScheduleCard();

        /**
         * Weitere Karten zum Zeitplan.
         *
         * @param string $pane
         */
        do_action('rh-backup/settings_modal', 'zeitplan');
        echo '</div>';

        echo '<div class="rhbp-tabpane" data-rhbp-pane="verlaesslich">';
        $this->renderReliabilityCard();
        echo '</div>';

        echo '</div>';
        echo '</div></div>';

        $this->printModalReopenScript();
    }

    /**
     * Öffnet das Modal nach dem Speichern wieder.
     *
     * Jede Einstellung führt über eine Weiterleitung zurück auf die Seite, und danach ist
     * das Modal zu. Wer zwei Dinge nacheinander einstellt, müsste es jedes Mal neu
     * aufklappen und den Bereich wiederfinden. Es geht deshalb dort wieder auf, wo es
     * war, samt Erfolgsmeldung.
     */
    private function printModalReopenScript(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Entscheidung.
        $key = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';

        // Was der Knopf in der Übersicht auslöst, gehört nicht ins Modal.
        $ausDemModal = [
            'offsite_saved' => 'zeitplan',
            'offsite_ping_regenerated' => 'verlaesslich',
        ];

        if (! isset($ausDemModal[$key])) {
            return;
        }

        $modalId = wp_json_encode(self::MODAL_ID);
        $pane = wp_json_encode($ausDemModal[$key]);

        // Geöffnet wird über den vorhandenen Knopf, nicht über einen Nachbau: wie ein
        // Modal genau aufgeht, weiss der Core, und das soll auch so bleiben.
        //
        // Erst wenn die Seite fertig geladen ist. Dieses Skript steht im Rumpf, der
        // Zuhörer des Core kommt aus einer Datei im Fussbereich, und ein Klick vorher
        // liefe ins Leere.
        $script = <<<JS
window.addEventListener('load', function(){
    var backdrop = document.getElementById({$modalId});
    var oeffner = document.querySelector('[data-rhbp-modal-open="' + {$modalId} + '"]');
    if (!backdrop || !oeffner) { return; }

    oeffner.click();

    var ziel = {$pane};
    backdrop.querySelectorAll('[data-rhbp-subtab]').forEach(function(t){
        t.classList.toggle('is-active', t.getAttribute('data-rhbp-subtab') === ziel);
    });
    backdrop.querySelectorAll('[data-rhbp-pane]').forEach(function(p){
        p.classList.toggle('is-active', p.getAttribute('data-rhbp-pane') === ziel);
    });
});
JS;

        wp_print_inline_script_tag($script);
    }

    /**
     * Zeigt, ob die Zeitsteuerung wirklich läuft, und wie man sie absichert.
     *
     * WP-Cron braucht Besucher. Auf einer Seite mit wenig Verkehr passiert deshalb unter
     * Umständen monatelang nichts, ohne dass es auffällt. Der Aufruf von aussen macht
     * daraus einen echten Termin, aber nur, wenn ihn jemand einrichtet. Darum steht der
     * fertige Befehl hier zum Kopieren.
     */
    private function renderReliabilityCard(): void
    {
        $problems = CronHealth::problems();
        $lastPing = PingEndpoint::lastPing();
        $url = PingEndpoint::url();

        echo '<div class="rhbp-db-card">';
        echo '<div class="rhbp-offsite__head">';
        echo '<h2>' . esc_html__('Verlässliche Läufe', 'rh-backup') . '</h2>';
        echo $problems === []
            ? '<span class="rhbp-pill rhbp-pill--ok"><span class="rhbp-pill__dot" aria-hidden="true"></span> ' . esc_html__('läuft', 'rh-backup') . '</span>'
            : '<span class="rhbp-pill rhbp-pill--err">' . esc_html__('auffällig', 'rh-backup') . '</span>';
        echo '</div>';

        if ($problems !== []) {
            echo '<div class="rhbp-callout rhbp-callout--warn"><ul>';
            foreach ($problems as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<p class="rhbp-offsite__hint">';
        echo esc_html__('WordPress führt geplante Aufgaben nur aus, wenn jemand die Seite aufruft. Damit die Sicherung auch ohne Besucher stattfindet, diesen Befehl einmal täglich vom Server oder einem Uptime-Dienst ausführen lassen.', 'rh-backup');
        echo '</p>';

        if ($url !== '') {
            echo '<div class="rhbp-codebox">';
            echo '<code id="rhbp-ping-cmd">curl -s "' . esc_html($url) . '"</code>';
            printf(
                '<button type="button" class="button" data-rhbp-copy="#rhbp-ping-cmd">%s</button>',
                esc_html__('Kopieren', 'rh-backup')
            );
            echo '</div>';
        }

        echo '<div class="rhbp-offsite__stats" style="margin-top:16px">';
        echo '<div class="rhbp-offsite__stat">';
        echo '<span class="rhbp-offsite__stat-label">' . esc_html__('Letzter Aufruf von aussen', 'rh-backup') . '</span>';
        echo '<span class="rhbp-offsite__stat-value">';
        echo $lastPing !== null
            ? esc_html(wp_date('d.m.Y H:i', $lastPing))
            : esc_html__('noch keiner', 'rh-backup');
        echo '</span></div>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-db-card__actions">';
        wp_nonce_field(self::NONCE_PING);
        echo '<input type="hidden" name="action" value="rhbackup_ping_regenerate" />';
        printf(
            '<button type="submit" class="button">%s</button>',
            esc_html__('Merkmal neu erzeugen', 'rh-backup')
        );
        echo '<span class="rhbp-offsite__hint">' . esc_html__('Danach gilt nur noch der neue Befehl.', 'rh-backup') . '</span>';
        echo '</form>';

        echo '</div>';
    }

    /**
     * Übernimmt die Wahl, wo die Sicherungen liegen sollen.
     */
    /**
     * Übernimmt die Wahl des Ablageorts.
     *
     * Was am alten Ort liegt, bleibt zunächst liegen. Ob es mitkommt oder weg soll, ist
     * eine eigene Entscheidung, und die trifft der Betreiber im nächsten Schritt.
     */
    public function handleMode(): void
    {
        $this->guard(self::NONCE_MODE);

        $laufend = TransferJob::load();
        if ($laufend !== null && ! $laufend->isFinished()) {
            $this->redirectError(__('Solange ein Umzug läuft, lässt sich der Ablageort nicht wechseln.', 'rh-backup'));
        }

        $gewuenscht = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $store = $this->stores->get($gewuenscht);

        if ($store === null) {
            $this->redirectError(__('Diesen Ablageort gibt es nicht.', 'rh-backup'));
        }

        // Ohne benutzbaren Ort gäbe es keinen Platz für die nächste Sicherung. Dann
        // lieber beim bisherigen bleiben als gar nicht sichern.
        if (! $store->isReady()) {
            $this->redirectError($store->notReadyReason());
        }

        rhbp_update_setting(Settings::GROUP, Settings::MODE, $gewuenscht);

        $this->redirect('offsite_mode_saved');
    }

    /**
     * Zieht die Sicherungen vom alten Ort an den aktuellen um.
     */
    public function handleMove(): void
    {
        $this->guard(self::NONCE_MOVE);

        $von = isset($_POST['from']) ? sanitize_key(wp_unslash($_POST['from'])) : '';
        $quelle = $this->stores->get($von);
        $ziel = $this->stores->current();

        if ($quelle === null || $quelle->id() === $ziel->id()) {
            $this->redirectError(__('Von dort gibt es nichts umzuziehen.', 'rh-backup'));
        }

        try {
            // Mit Löschen am alten Ort, aber erst nachdem die Kopie angekommen ist.
            // Das ist die ganze Idee: es gibt immer genau einen Ort.
            $this->transfer->start($quelle->id(), $ziel->id(), $quelle->list(), true);
        } catch (\Throwable $e) {
            $this->redirectError($e->getMessage());
        }

        $this->redirect('offsite_move_started');
    }

    /**
     * Entfernt die Sicherungen am alten Ort, ohne sie mitzunehmen.
     */
    public function handlePurgeLocal(): void
    {
        $this->guard(self::NONCE_PURGE);

        $von = isset($_POST['from']) ? sanitize_key(wp_unslash($_POST['from'])) : '';
        $quelle = $this->stores->get($von);

        if ($quelle === null || $quelle->id() === $this->stores->current()->id()) {
            $this->redirectError(__('Von dort gibt es nichts zu entfernen.', 'rh-backup'));
        }

        $anzahl = 0;
        $bytes = 0;

        foreach ($quelle->list() as $eintrag) {
            if ($quelle->delete($eintrag->ref)) {
                $anzahl++;
                $bytes += $eintrag->size;
            }
        }

        if ($anzahl > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nachvollziehbarkeit gelöschter Backups.
            error_log(sprintf(
                '[rh-backup] Ablageort gewechselt: %d Sicherungen bei "%s" entfernt (%s).',
                $anzahl,
                $quelle->id(),
                size_format($bytes)
            ));
        }

        $this->redirect('offsite_purged', sprintf(
            /* translators: %1$d: number of files, %2$s: total size */
            _n(
                '%1$d Sicherung entfernt (%2$s).',
                '%1$d Sicherungen entfernt (%2$s).',
                $anzahl,
                'rh-backup'
            ),
            $anzahl,
            size_format($bytes)
        ));
    }

    /**
     * Erzeugt das Merkmal neu, etwa wenn der bisherige Befehl irgendwo gelandet ist,
     * wo er nicht hingehört.
     */
    public function handleRegeneratePing(): void
    {
        Guard::form(self::NONCE_PING);
        PingEndpoint::regenerateToken();

        $this->redirect('offsite_ping_regenerated');
    }

    // ============================================================
    // Kleine Skripte, ohne Build-Schritt
    // ============================================================

    private function printPollingScript(): void
    {
        $nonce = wp_create_nonce(self::NONCE_AJAX);
        $ajaxUrl = admin_url('admin-ajax.php');

        $script = <<<JS
(function(){
    var tries = 0;
    function poll(){
        if (++tries > 180) { return; }
        var body = new FormData();
        body.append('action', 'rhbackup_offsite_poll');
        body.append('nonce', '{$nonce}');
        fetch('{$ajaxUrl}', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && res.success && res.data && res.data.status === 'connected') {
                    window.location.reload();
                    return;
                }
                if (res && res.data && res.data.status === 'stopped') { return; }
                setTimeout(poll, 5000);
            })
            .catch(function(){ setTimeout(poll, 8000); });
    }
    setTimeout(poll, 4000);
})();
JS;

        wp_print_inline_script_tag($script);
    }

    private function printStatusScript(): void
    {
        $ajaxUrl = admin_url('admin-ajax.php');
        $i18n = wp_json_encode([
            'stale' => __('Der Vorgang meldet sich seit einer Weile nicht. Der Wächter versucht, ihn wieder in Gang zu bringen.', 'rh-backup'),
            'waiting' => __('Kurze Pause nach einem Fehlversuch, es geht gleich weiter.', 'rh-backup'),
        ]);

        // Durchgehend fragen, nicht nur wenn beim Seitenaufbau schon etwas lief. Sonst
        // bleibt die Anzeige leer, wenn der planmässige Lauf startet, während die Seite
        // offen ist. Im Ruhezustand träge, bei laufendem Vorgang häufig.
        $script = <<<JS
(function(){
    var box = document.querySelector('[data-rhbp-offsite-status]');
    if (!box) { return; }
    var nonce = box.getAttribute('data-nonce');
    var war = box.getAttribute('data-active') === '1';
    var i18n = {$i18n};
    var bar = box.querySelector('[data-rhbp-offsite-bar]');
    var text = box.querySelector('[data-rhbp-offsite-text]');

    function tick(){
        var body = new FormData();
        body.append('action', 'rhbackup_offsite_status');
        body.append('nonce', nonce);
        fetch('{$ajaxUrl}', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success || !res.data) { setTimeout(tick, 15000); return; }
                var d = res.data;

                if (!d.running) {
                    // Lief eben noch etwas, ist das Ergebnis jetzt da: neu laden, damit
                    // Status und Liste den fertigen Stand zeigen.
                    if (war) { window.location.reload(); return; }
                    box.hidden = true;
                    setTimeout(tick, 15000);
                    return;
                }

                war = true;
                box.hidden = false;
                box.classList.toggle('is-stale', !!d.stale);

                if (bar) {
                    // Solange die Grösse des Archivs nicht feststeht, gibt es keinen
                    // ehrlichen Anteil. Dann läuft der Balken unbestimmt weiter.
                    var unbestimmt = (d.percent === null || d.percent === undefined);
                    bar.parentNode.classList.toggle('is-indeterminate', unbestimmt);
                    bar.style.width = unbestimmt ? '100%' : (d.percent + '%');
                }

                if (text) {
                    var teile = [d.label || d.message];
                    if (d.detail) { teile.push(d.detail); }
                    if (d.percent !== null && d.percent !== undefined) { teile.push(d.percent + '%'); }
                    var zeile = teile.join(', ');
                    if (d.stale) { zeile += '. ' + i18n.stale; }
                    else if (d.waiting) { zeile += '. ' + i18n.waiting; }
                    text.textContent = zeile;
                }

                setTimeout(tick, 3000);
            })
            .catch(function(){ setTimeout(tick, 15000); });
    }
    tick();
})();
JS;

        wp_print_inline_script_tag($script);
    }

    // ============================================================
    // Aktionen
    // ============================================================

    public function handleConnect(): void
    {
        $this->guard(self::NONCE_CONNECT);

        try {
            $device = $this->drive->requestDeviceCode();
        } catch (\Throwable $e) {
            $this->redirectError($e->getMessage());
        }

        set_transient(self::PENDING_TRANSIENT, $device, max(60, (int) $device['expires_in']));
        $this->redirect('offsite_pending');
    }

    public function handleDisconnect(): void
    {
        $this->guard(self::NONCE_DISCONNECT);

        $this->connection->disconnect();
        delete_transient(self::PENDING_TRANSIENT);
        Scheduler::unscheduleAll();

        $this->redirect('offsite_disconnected');
    }

    public function handleSave(): void
    {
        $this->guard(self::NONCE_SAVE);

        $interval = isset($_POST['interval']) ? sanitize_key(wp_unslash($_POST['interval'])) : '';
        if (! isset(Settings::intervals()[$interval])) {
            $interval = Settings::DEFAULT_INTERVAL;
        }

        $email = isset($_POST['notify_email']) ? sanitize_email(wp_unslash($_POST['notify_email'])) : '';

        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';
        if (! isset(Settings::scopes()[$scope])) {
            $scope = Settings::scope();
        }

        rhbp_update_settings(Settings::GROUP, [
            Settings::INTERVAL => $interval,
            Settings::KEEP_COPIES => max(1, min(365, (int) ($_POST['keep_copies'] ?? Settings::DEFAULT_KEEP_COPIES))),
            Settings::FOLDER_NAME => isset($_POST['folder_name'])
                ? sanitize_text_field(wp_unslash($_POST['folder_name']))
                : Settings::DEFAULT_FOLDER_NAME,
            Settings::NOTIFY_EMAIL => $email,
            Settings::SCOPE => $scope,
            Settings::INCLUDE_CONFIG => ! empty($_POST['include_config']),
            // Weiter mitgeschrieben, damit ältere Auswertungen und Filter nicht ins
            // Leere greifen. Massgeblich ist der Umfang.
            Settings::INCLUDE_UPLOADS => $scope !== Settings::SCOPE_DATABASE,
        ]);

        // Der Ordnername kann sich geändert haben, dann muss er neu gesucht werden.
        $this->connection->forgetFolderId();

        // Zeitplan sofort an die neue Einstellung anpassen.
        $scheduled = wp_next_scheduled(Scheduler::RUN_HOOK);
        if ($scheduled !== false) {
            wp_unschedule_event($scheduled, Scheduler::RUN_HOOK);
        }
        wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, Scheduler::RUN_HOOK);

        $this->redirect('offsite_saved');
    }

    public function handleRun(): void
    {
        $this->guard(self::NONCE_RUN);

        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';

        try {
            $this->runner->start('manual', isset(Settings::scopes()[$scope]) ? $scope : '');
        } catch (\Throwable $e) {
            $this->redirectError($e->getMessage());
        }

        $this->redirect('offsite_started');
    }

    public function ajaxPoll(): void
    {
        $this->guardAjax();

        $pending = get_transient(self::PENDING_TRANSIENT);
        if (! is_array($pending) || empty($pending['device_code'])) {
            wp_send_json_success(['status' => 'stopped']);
        }

        $result = $this->drive->pollDeviceCode((string) $pending['device_code']);

        if ($result['status'] === 'connected') {
            try {
                $this->connection->storeRefreshToken((string) ($result['refresh_token'] ?? ''));
                if (! empty($result['access_token'])) {
                    $this->connection->storeAccessToken((string) $result['access_token'], (int) ($result['expires_in'] ?? 3600));
                }

                $email = $this->drive->accountEmail();
                if ($email !== '') {
                    $this->connection->storeRefreshToken((string) ($result['refresh_token'] ?? ''), $email);
                }
            } catch (\Throwable $e) {
                delete_transient(self::PENDING_TRANSIENT);
                wp_send_json_success(['status' => 'stopped', 'message' => $e->getMessage()]);
            }

            delete_transient(self::PENDING_TRANSIENT);

            // Ab jetzt darf der Zeitplan greifen.
            wp_schedule_event(time() + HOUR_IN_SECONDS, Settings::interval(), Scheduler::RUN_HOOK);

            wp_send_json_success(['status' => 'connected']);
        }

        if (in_array($result['status'], ['denied', 'expired', 'error'], true)) {
            delete_transient(self::PENDING_TRANSIENT);
            wp_send_json_success(['status' => 'stopped', 'message' => $result['message'] ?? '']);
        }

        wp_send_json_success(['status' => 'pending']);
    }

    public function ajaxStatus(): void
    {
        $this->guardAjax();

        $job = UploadJob::load();
        if ($job === null || $job->isFinished()) {
            wp_send_json_success(['running' => false]);
        }

        wp_send_json_success(array_merge(
            ['running' => true, 'message' => $job->message],
            $job->progressInfo()
        ));
    }

    // ============================================================
    // Helfer
    // ============================================================

    private function guard(string $nonce): void
    {
        Guard::form($nonce, self::CAPABILITY);
    }

    private function guardAjax(): void
    {
        Guard::ajax(self::NONCE_AJAX, self::CAPABILITY);
    }

    private function redirect(string $message, string $detail = ''): never
    {
        $args = [
            'page' => 'rh-blueprint',
            'tab' => self::TAB_ID,
            BackupTabs::PARAM => $this->paneFromRequest(),
            'rhbp_message' => $message,
        ];

        if ($detail !== '') {
            $args['rhbp_detail'] = rawurlencode($detail);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function redirectError(string $detail): never
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'rh-blueprint',
            'tab' => self::TAB_ID,
            BackupTabs::PARAM => $this->paneFromRequest(),
            'rhbp_message' => 'offsite_error',
            'rhbp_detail' => rawurlencode($detail),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Aus welchem Bereich kam die Aktion?
     *
     * Ohne diese Angabe landet man nach jedem Speichern wieder in der Übersicht statt
     * dort, wo man gerade gearbeitet hat. Das Formular führt sie deshalb mit.
     */
    private function paneFromRequest(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- die Aktion selbst ist bereits geprüft, das hier steuert nur die Anzeige.
        $wunsch = isset($_POST[BackupTabs::PARAM]) ? sanitize_key(wp_unslash($_POST[BackupTabs::PARAM])) : '';

        return isset(BackupTabs::panes()[$wunsch]) ? $wunsch : BackupTabs::PANE_SETTINGS;
    }
}
