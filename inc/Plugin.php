<?php

declare(strict_types=1);

namespace RhBackup;

use RhBackup\Admin\DbToolsPage;
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
    }

    public static function onCoreBooted(Core $core): void
    {
        if (! function_exists('rh_db_engine')) {
            return;
        }

        $engine = rh_db_engine();

        // Backup-Tab und DB-Tools-UI in die geteilte Settings-Page einklinken.
        $core->settings()->registerTab('backup', __('Backup', 'rh-backup'), 20);
        (new DbToolsPage($engine->storage(), $engine->exporter(), $engine->importer()))->boot();

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
