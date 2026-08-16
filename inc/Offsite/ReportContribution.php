<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

use RhBlueprint\Core\Admin\MailPanel;
use RhBlueprint\Core\Mail\MailKind;
use RhBlueprint\Core\Mail\MailMessage;
use RhBlueprint\Core\Mail\MailSettings;
use RhBlueprint\Core\Mail\ReportSection;

/**
 * Der Beitrag des Backup-Moduls zum Sammelbericht.
 *
 * Ein Fehlschlag geht sofort raus, dafür gibt es den Notifier. Was hier
 * dazukommt, ist der umgekehrte Fall: die Sicherung lief, und niemand erfährt
 * davon. Für einen Wartungsvertrag ist genau das der Nachweis, und für den
 * Betreiber die einzige Gelegenheit zu merken, dass seit Wochen nichts mehr
 * gesichert wurde, ohne dass etwas fehlgeschlagen wäre.
 */
final class ReportContribution
{
    /** Kennungen der Mail-Arten dieses Moduls. */
    public const KIND_FAILURE = 'backup.failure';
    public const KIND_CRON = 'backup.cron';
    public const KIND_REPORT = 'backup.report';

    public function boot(): void
    {
        $this->registerKinds();

        add_filter('rh-blueprint/report/sections', [$this, 'section'], 10, 2);
        add_filter('rh-blueprint/addon_hints', [$this, 'addonHint']);

        // Inhalt des Mail-Reiters in der Leiste des Moduls.
        add_action('rh-backup/pane', [$this, 'renderMailPane']);
    }

    public function renderMailPane(string $pane): void
    {
        if ($pane === MailPanel::TAB) {
            (new MailPanel())->render('backup');
        }
    }

    private function registerKinds(): void
    {
        MailKind::register(self::KIND_FAILURE, [
            'module' => 'backup',
            'label' => __('Sicherung fehlgeschlagen', 'rh-backup'),
            'summary' => __('Sobald ein Lauf abbricht. Das Schlimmste an einer Sicherung ist, dass sie still ausfällt.', 'rh-backup'),
            'urgent' => true,
        ]);

        MailKind::register(self::KIND_CRON, [
            'module' => 'backup',
            'label' => __('Zeitsteuerung läuft nicht', 'rh-backup'),
            'summary' => __('Wenn seit längerem gar kein Lauf mehr stattgefunden hat.', 'rh-backup'),
            'urgent' => true,
        ]);

        MailKind::register(self::KIND_REPORT, [
            'module' => 'backup',
            'label' => __('Abschnitt Sicherung', 'rh-backup'),
            'summary' => __('Wann zuletzt gesichert wurde und wie viel. Der Nachweis für den Wartungsvertrag.', 'rh-backup'),
            'timing' => MailKind::TIMING_REPORT,
        ]);
    }

    /**
     * @param array<int, ReportSection> $sections
     * @return array<int, ReportSection>
     */
    public function section(array $sections, int $since): array
    {
        if (! MailSettings::enabled(self::KIND_REPORT)) {
            return $sections;
        }

        $last = get_option(Settings::OPTION_LAST_RUN, []);
        $last = is_array($last) ? $last : [];

        $time = (int) ($last['time'] ?? 0);
        $success = (bool) ($last['success'] ?? false);
        $detail = new MailMessage('');

        if ($time === 0) {
            $sections[] = (new ReportSection(
                'backup',
                __('Sicherung', 'rh-backup'),
                ReportSection::STATUS_WARN,
                __('noch nie gelaufen', 'rh-backup')
            ))->detail(
                $detail->text(__('Für diese Website ist bisher keine Sicherung nach Google Drive gelaufen.', 'rh-backup'))
            )->link($this->url());

            return $sections;
        }

        $detail->rows([
            __('Zuletzt', 'rh-backup') => wp_date('d.m.Y H:i', $time),
            __('Umfang', 'rh-backup') => size_format((int) ($last['size'] ?? 0)),
            // Nicht den rohen Wert aus der Option: "schedule" sagt einem
            // Betreiber nichts, und der Bericht soll lesbar sein.
            __('Anlass', 'rh-backup') => (string) ($last['trigger'] ?? '') === 'schedule'
                ? __('planmässig', 'rh-backup')
                : __('von Hand', 'rh-backup'),
        ]);

        // Älter als der Berichtszeitraum heisst: in diesem Zeitraum ist nichts
        // passiert. Das ist auffällig, auch wenn nichts fehlgeschlagen ist.
        $stale = $time < $since;

        $status = match (true) {
            ! $success => ReportSection::STATUS_ALERT,
            $stale => ReportSection::STATUS_WARN,
            default => ReportSection::STATUS_OK,
        };

        $summary = match (true) {
            ! $success => __('letzter Lauf fehlgeschlagen', 'rh-backup'),
            $stale => __('in diesem Zeitraum nicht gelaufen', 'rh-backup'),
            default => sprintf(
                /* translators: %s: Datum der letzten Sicherung */
                __('zuletzt am %s, erfolgreich', 'rh-backup'),
                wp_date('d.m.Y', $time)
            ),
        };

        if (! $success && ($last['message'] ?? '') !== '') {
            $detail->text(sprintf(
                /* translators: %s: Fehlermeldung */
                __('Grund: %s', 'rh-backup'),
                (string) $last['message']
            ));
        }

        $sections[] = (new ReportSection('backup', __('Sicherung', 'rh-backup'), $status, $summary))
            ->detail($detail)
            ->link($this->url());

        return $sections;
    }

    /**
     * @param array<int, array{tab: string, module: string, benefit: string}> $hints
     * @return array<int, array{tab: string, module: string, benefit: string}>
     */
    public function addonHint(array $hints): array
    {
        $hints[] = [
            'tab' => 'backup',
            'module' => 'rh-smtp',
            'benefit' => __('Eine Meldung, wenn eine Sicherung fehlschlägt, und der Nachweis im Sammelbericht, dass regelmässig gesichert wurde.', 'rh-backup'),
        ];

        return $hints;
    }

    private function url(): string
    {
        return admin_url('admin.php?page=rh-blueprint&tab=backup');
    }
}
