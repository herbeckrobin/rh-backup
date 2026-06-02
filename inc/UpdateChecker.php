<?php

declare(strict_types=1);

namespace RhBackup;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * GitHub-basierter Auto-Update-Checker für rh-backup.
 *
 * Hängt sich in die native WordPress-Update-Mechanik. Kein eigenes UI, Updates
 * erscheinen wie normale Plugin-Updates. Die Library zieht das ZIP-Asset aus den
 * GitHub Releases (Tags `v*`), damit das committed `vendor/` (inkl. Core) enthalten ist.
 */
final class UpdateChecker
{
    public const GITHUB_REPO = 'https://github.com/herbeckrobin/rh-backup/';
    public const PLUGIN_SLUG = 'rh-backup';

    public function boot(): void
    {
        // Ausserhalb von WordPress (z.B. Standalone-Tests) nichts tun.
        if (! function_exists('add_filter') || ! class_exists(PucFactory::class)) {
            return;
        }

        $updateChecker = PucFactory::buildUpdateChecker(
            self::GITHUB_REPO,
            RHBACKUP_PLUGIN_FILE,
            self::PLUGIN_SLUG
        );

        $vcsApi = $updateChecker->getVcsApi();
        if ($vcsApi !== null && method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }
    }
}
