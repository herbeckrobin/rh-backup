<?php

/**
 * Plugin Name:       RH Backup
 * Plugin URI:        https://github.com/herbeckrobin/rh-backup
 * Update URI:        https://github.com/herbeckrobin/rh-backup
 * Description:       DB-Backup, Export, Import und Restore für WordPress. Pure PHP, Shared-Hosting-tauglich. Teil der rh-blueprint Kollektion.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-backup
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHBACKUP_VERSION', '0.1.0');
define('RHBACKUP_PLUGIN_FILE', __FILE__);
define('RHBACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));

$rhbackup_autoload = RHBACKUP_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhbackup_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Backup:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhbackup_autoload;

RhBackup\Plugin::boot();
