<?php

declare(strict_types=1);

namespace RhBackup\Admin;

use RhBlueprint\Core\Admin\MailPanel;

/**
 * Teilt den Backup-Bereich in Untertabs auf.
 *
 * Zwei Bereiche, mehr braucht es nicht. In der Übersicht stehen die beiden Dinge, die
 * man wirklich tut: eine Sicherung von Hand anstossen, und nachsehen ob die
 * automatische läuft. Alles was man einmal einstellt, liegt hinter dem Zahnrad der
 * automatischen Sicherung und nicht als eigener Bereich daneben.
 *
 * Wiederherstellen ist getrennt, weil es der einzige Vorgang ist, der etwas überschreibt.
 *
 * Die Bereiche selbst füllt niemand hier. Wer eine Karte beisteuern will, hängt sich an
 * `rh-backup/pane` und prüft den Namen. So bleibt die Aufteilung an einer Stelle, und
 * die Karten bleiben dort, wo sie inhaltlich hingehören.
 */
final class BackupTabs
{
    public const TAB_ID = 'backup';

    /** Query-Parameter, damit ein Neuladen im selben Bereich landet. */
    public const PARAM = 'sub';

    public const PANE_OVERVIEW = 'overview';
    public const PANE_RESTORE = 'restore';

    /**
     * @return array<string, string>
     */
    public static function panes(): array
    {
        $panes = [
            self::PANE_OVERVIEW => __('Übersicht', 'rh-backup'),
            self::PANE_RESTORE => __('Wiederherstellen', 'rh-backup'),
        ];

        // Die Mail-Einstellungen kommen aus dem Core, gehören aber in diese
        // Leiste und nicht in eine zweite darüber.
        $mail = MailPanel::tabLabel(self::TAB_ID);

        if ($mail !== null) {
            $panes[MailPanel::TAB] = $mail;
        }

        return $panes;
    }

    /**
     * Welcher Bereich soll offen sein? Nach einer Aktion der, aus dem sie kam.
     */
    public static function current(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
        $wunsch = isset($_GET[self::PARAM]) ? sanitize_key((string) $_GET[self::PARAM]) : '';

        return isset(self::panes()[$wunsch]) ? $wunsch : self::PANE_OVERVIEW;
    }

    public function boot(): void
    {
        // Priorität 5: vor allen, die Karten beisteuern. Die rendern nicht mehr selbst,
        // sondern warten auf den Aufruf von hier.
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render'], 5);
    }

    public function render(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $aktiv = self::current();

        echo '<div class="rhbp-backup-tabs" data-rhbp-backup-tabs>';

        echo '<div class="rhbp-subtabs">';
        foreach (self::panes() as $key => $label) {
            printf(
                '<button type="button" class="rhbp-subtab%s" data-rhbp-backup-subtab="%s">%s</button>',
                $key === $aktiv ? ' is-active' : '',
                esc_attr($key),
                esc_html($label)
            );
        }
        echo '</div>';

        foreach (array_keys(self::panes()) as $key) {
            printf(
                '<div class="rhbp-tabpane%s" data-rhbp-backup-pane="%s">',
                $key === $aktiv ? ' is-active' : '',
                esc_attr($key)
            );

            /**
             * Karten für diesen Bereich.
             *
             * @param string $pane
             */
            do_action('rh-backup/pane', $key);

            echo '</div>';
        }

        echo '</div>';

        $this->printScript();
    }

    /**
     * Umschalten ohne Neuladen, aber mit sichtbarer Spur in der Adresszeile.
     *
     * Die Adresse mitzuführen ist kein Beiwerk: nach jedem Speichern kommt man über eine
     * Weiterleitung zurück, und ohne den Hinweis in der Adresse landet man wieder in der
     * Übersicht statt dort, wo man gerade gearbeitet hat.
     */
    private function printScript(): void
    {
        $script = <<<'JS'
(function(){
    var wurzel = document.querySelector('[data-rhbp-backup-tabs]');
    if (!wurzel) { return; }

    wurzel.addEventListener('click', function(e){
        var knopf = e.target.closest('[data-rhbp-backup-subtab]');
        if (!knopf) { return; }

        var key = knopf.getAttribute('data-rhbp-backup-subtab');
        wurzel.querySelectorAll('[data-rhbp-backup-subtab]').forEach(function(t){
            t.classList.toggle('is-active', t === knopf);
        });
        wurzel.querySelectorAll('[data-rhbp-backup-pane]').forEach(function(p){
            p.classList.toggle('is-active', p.getAttribute('data-rhbp-backup-pane') === key);
        });

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('sub', key);
            window.history.replaceState({}, '', url);
        } catch (err) {}
    });

    // Jedes Formular führt beim Absenden seinen Bereich mit. Das hier statt eines
    // versteckten Feldes in jedem einzelnen Formular: es sind acht, verteilt über zwei
    // Klassen, und jedes neue würde man vergessen.
    wurzel.addEventListener('submit', function(e){
        var form = e.target;
        if (!form || form.tagName !== 'FORM') { return; }

        var pane = form.closest('[data-rhbp-backup-pane]');
        if (!pane || form.querySelector('input[name="sub"]')) { return; }

        var feld = document.createElement('input');
        feld.type = 'hidden';
        feld.name = 'sub';
        feld.value = pane.getAttribute('data-rhbp-backup-pane');
        form.appendChild(feld);
    });
})();
JS;

        wp_print_inline_script_tag($script);
    }
}
