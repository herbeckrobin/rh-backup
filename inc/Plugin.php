<?php

declare(strict_types=1);

namespace RhBackup;

use RhBackup\Admin\BackupProgressIndicator;
use RhBackup\Admin\BackupTabs;
use RhBackup\Admin\DbToolsPage;
use RhBackup\Admin\OffsitePage;
use RhBackup\Cron\CronHealth;
use RhBackup\Cron\PingEndpoint;
use RhBackup\Offsite\Connection;
use RhBackup\Offsite\GoogleDrive;
use RhBackup\Offsite\Notifier;
use RhBackup\Offsite\Scheduler;
use RhBackup\Offsite\UploadAdvancer;
use RhBackup\Offsite\UploadRunner;
use RhBackup\Storage\RestoreRunner;
use RhBackup\Storage\StoreRegistry;
use RhBackup\Storage\TransferRunner;
use RhBlueprint\Core\Core;

/**
 * Bootstrap von rh-backup.
 *
 * Hängt sich an den Core-Hook `rh-blueprint/core/booted`. Die DB-Funktionalität
 * (Export/Import/Storage) kommt aus dem geteilten db-engine-Package (`rh_db_engine()`),
 * nicht mehr aus dem Core. rh-backup ist damit eine reine UI über der Engine.
 */
final class Plugin
{
    public static function boot(): void
    {
        // Auto-Update läuft unabhängig vom Core. Im WordPress.org-Build wird der
        // UpdateChecker entfernt (WP.org liefert Updates selbst), darum defensiv.
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);

        // Termine nicht als Leichen zurücklassen, wenn das Plugin deaktiviert wird.
        if (function_exists('register_deactivation_hook') && defined('RHBACKUP_PLUGIN_FILE')) {
            register_deactivation_hook(RHBACKUP_PLUGIN_FILE, [Maintenance::class, 'unschedule']);
            register_deactivation_hook(RHBACKUP_PLUGIN_FILE, [Scheduler::class, 'unscheduleAll']);
        }
    }

    public static function onCoreBooted(Core $core): void
    {
        if (! function_exists('rh_db_engine')) {
            return;
        }

        $engine = rh_db_engine();

        $core->settings()->registerTab('backup', __('Backup', 'rh-backup'), 20);

        $connection = new Connection();
        $drive = new GoogleDrive($connection);

        // Wo die Sicherungen liegen, entscheidet eine Stelle. Alles was mit Sicherungen
        // umgeht, fragt sie, statt selbst aus der Einstellung abzuleiten, ob es die
        // Platte oder Google Drive ist.
        $stores = new StoreRegistry($engine->storage(), $drive, $connection);

        $restore = new RestoreRunner($engine->storage(), $engine->exporter(), $engine->importer());
        $restore->boot();

        // Bewegt Sicherungen zwischen den Ablageorten: beim Umschalten, und um eine
        // Sicherung aus Google Drive zum Einspielen herzuholen.
        $transfer = new TransferRunner($stores, $restore);
        $transfer->boot();

        // Rotation der Backups und Aufräumen verwaister Job-Ordner.
        (new Maintenance($engine->storage()))->boot();

        $runner = new UploadRunner(
            new UploadAdvancer($engine->storage(), $engine->exporter(), $drive, $connection),
            new Notifier()
        );
        $runner->boot();

        (new Scheduler($runner))->boot();
        (new BackupTabs())->boot();
        (new DbToolsPage($engine->storage(), $engine->exporter(), $stores, $restore, $transfer))->boot();
        (new OffsitePage($connection, $drive, $runner, $stores, $transfer))->boot();
        (new BackupProgressIndicator())->boot();

        // Verlässliche Läufe: von aussen anstossbar, und eine Selbstprüfung für die
        // Fälle, in denen niemand einen Aufruf eingerichtet hat.
        (new PingEndpoint($runner))->boot();
        (new CronHealth(new Notifier()))->boot();

        // Dashboard-Quick-Link beisteuern.
        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Backup', 'rh-backup'),
                'url' => admin_url('admin.php?page=' . \RhBlueprint\Core\Settings\SettingsPage::MENU_SLUG . '&tab=backup'),
                'icon' => 'database',
            ];
            return $links;
        });
    }
}
