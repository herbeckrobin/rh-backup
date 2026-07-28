<?php

declare(strict_types=1);

namespace RhBackup\Admin;

use RhBackup\Offsite\UploadJob;
use RhBlueprint\Core\Settings\SettingsPage;

/**
 * Zeigt einen laufenden Vorgang auf jeder Seite des Backends.
 *
 * Eine Sicherung läuft im Hintergrund weiter, auch wenn niemand hinschaut. Wer nach dem
 * Start weiterarbeitet, verliert sie sonst aus den Augen und weiss nicht, ob sie noch
 * läuft, hängt oder längst fertig ist. Der Eintrag in der Adminleiste beantwortet das,
 * ohne dass man zurück in den Backup-Bereich muss.
 *
 * Vorbild ist der Indikator von rh-sync, mit einem Unterschied: hier zählt nicht nur,
 * ob ein Vorgang existiert, sondern ob er sich noch meldet. Sonst zeigt die Leiste einen
 * längst toten Lauf tagelang als aktiv an.
 */
final class BackupProgressIndicator
{
    public function boot(): void
    {
        add_action('admin_bar_menu', [$this, 'addNode'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_head', [$this, 'printBaseStyle']);
    }

    public function addNode(\WP_Admin_Bar $bar): void
    {
        if (! current_user_can(OffsitePage::CAPABILITY)) {
            return;
        }

        // Der Knoten wird immer angelegt, aber zunächst verborgen. So kann das Skript ihn
        // einblenden, sobald ein Lauf startet, ohne dass die Seite neu geladen werden muss.
        $bar->add_node([
            'id' => 'rhbp-backup-indicator',
            'title' => '<span class="ab-icon dashicons dashicons-backup" style="margin-top:2px"></span>'
                . '<span class="rhbp-backup-ind-label">' . esc_html__('Sicherung', 'rh-backup') . '</span>',
            'href' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=' . OffsitePage::TAB_ID),
            'meta' => ['class' => 'rhbp-backup-indicator-node rhbp-ind-hidden'],
        ]);
    }

    /**
     * Von Anfang an verborgen, damit er nicht bei jedem Seitenaufbau kurz aufblitzt.
     *
     * Verborgen wird über eine Klasse, nicht über den Stil am Element: das Skript müsste
     * sonst eine Anzeigeart erraten, um wieder einzublenden, und ein zurückgesetzter
     * Stil würde erneut auf diese Regel fallen.
     */
    public function printBaseStyle(): void
    {
        if (! current_user_can(OffsitePage::CAPABILITY)) {
            return;
        }

        echo '<style>#wpadminbar .rhbp-ind-hidden{display:none}</style>';
    }

    public function enqueue(): void
    {
        if (! current_user_can(OffsitePage::CAPABILITY)) {
            return;
        }

        wp_register_script('rhbp-backup-indicator', false, [], RHBACKUP_VERSION, true);
        wp_enqueue_script('rhbp-backup-indicator');

        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(OffsitePage::NONCE_AJAX),
            'stalled' => __('Sicherung hängt', 'rh-backup'),
            'idle' => __('Sicherung', 'rh-backup'),
        ]);

        $js = <<<JS
(function(){
    var cfg = window.__rhbpBackupInd;
    var node = document.getElementById('wp-admin-bar-rhbp-backup-indicator');
    if (!cfg || !node) { return; }
    var label = node.querySelector('.rhbp-backup-ind-label');
    var icon = node.querySelector('.ab-icon');

    function frage(){
        var body = new FormData();
        body.append('action', 'rhbackup_offsite_status');
        body.append('nonce', cfg.nonce);
        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var d = (res && res.data) ? res.data : {};
                if (d.running) {
                    node.classList.remove('rhbp-ind-hidden');
                    if (label) {
                        var t = d.stale ? cfg.stalled : (d.label || cfg.idle);
                        if (!d.stale && d.percent !== null && d.percent !== undefined) { t += ' ' + d.percent + '%'; }
                        label.textContent = t;
                    }
                    if (icon) { icon.style.color = d.stale ? '#dba617' : ''; }
                    setTimeout(frage, 3000);
                } else {
                    node.classList.add('rhbp-ind-hidden');
                    setTimeout(frage, 20000);
                }
            })
            .catch(function(){ setTimeout(frage, 30000); });
    }
    frage();
})();
JS;

        wp_add_inline_script('rhbp-backup-indicator', 'window.__rhbpBackupInd = ' . $config . ';', 'before');
        wp_add_inline_script('rhbp-backup-indicator', $js);
    }
}
