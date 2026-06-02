<?php

declare(strict_types=1);

namespace RhBackup;

use RhBackup\Admin\DbToolsPage;
use RhBackup\Db\Exporter;
use RhBackup\Db\Importer;
use RhBackup\Db\SearchReplace;
use RhBlueprint\Core\Core;

/**
 * Bootstrap von rh-backup.
 *
 * Das Plugin hängt sich an den Core-Hook `rh-blueprint/core/booted` statt direkt
 * zu booten. So ist sichergestellt, dass der Core (Service-Registry, Settings-Hub)
 * bereitsteht. Fehlt der Core, passiert nichts (graceful).
 */
final class Plugin
{
    public static function boot(): void
    {
        // Auto-Update läuft unabhängig vom Core.
        (new UpdateChecker())->boot();

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        $storage = $core->storage();
        $searchReplace = new SearchReplace();
        $exporter = new Exporter($storage);
        $importer = new Importer($storage, $searchReplace);

        // Backup-API für andere Plugins (rh-sync) bereitstellen.
        $core->services()->register('backup', new Api($exporter, $importer), Api::VERSION);

        // Tools-Tab und DB-Tools-UI in die geteilte Settings-Page einklinken.
        $core->settings()->registerTab('tools', __('Tools', 'rh-backup'), 20);
        (new DbToolsPage($storage, $exporter, $importer))->boot();
    }
}
