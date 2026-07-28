<?php

/**
 * Integrationstest für rh-backup + gebundelten Core.
 *
 *   php tests/integration-test.php
 *
 * Beweist die Boot-Kette ohne echtes WordPress:
 *   1. vendor/autoload.php lädt den Core-Entry-Point (Composer files-autoload)
 *      -> Core meldet seine Version beim Loader an.
 *   2. Plugin::boot() registriert den core/booted-Listener.
 *   3. plugins_loaded -> Negotiation bootet den Core -> core/booted feuert
 *      -> rh-backup registriert seinen Backup-Tab und die Wartung.
 *   4. Die DB-Engine ist erreichbar und liefert Storage, Exporter und Importer.
 */

declare(strict_types=1);

// --- WP-Stubs ----------------------------------------------------------------
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/rh-backup-test-content');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['__hooks'] = [];
$GLOBALS['__filters'] = [];
$GLOBALS['__options'] = [];
$GLOBALS['__cron'] = [];

function add_action(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
    $GLOBALS['__hooks'][$hook][] = $cb;
}

function do_action(string $hook, mixed ...$args): void
{
    foreach ($GLOBALS['__hooks'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}

function add_filter(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
    $GLOBALS['__filters'][$hook][] = $cb;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['__filters'][$hook] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }

    return $value;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_attr__(string $text, string $domain = 'default'): string
{
    return $text;
}

function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '');
}

function trailingslashit(string $s): string
{
    return rtrim($s, '/\\') . '/';
}

function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['__options'][$name] ?? $default;
}

function add_option(string $name, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
{
    if (array_key_exists($name, $GLOBALS['__options'])) {
        return false;
    }
    $GLOBALS['__options'][$name] = $value;

    return true;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['__options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['__options'][$name]);

    return true;
}

function wp_next_scheduled(string $hook): int|false
{
    return $GLOBALS['__cron'][$hook] ?? false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['__cron'][$hook] = $timestamp;

    return true;
}

function wp_unschedule_event(int $timestamp, string $hook): bool
{
    unset($GLOBALS['__cron'][$hook]);

    return true;
}

function register_deactivation_hook(string $file, callable $cb): void
{
}

function get_current_user_id(): int
{
    return 1;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function is_admin(): bool
{
    return true;
}

function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

// --- Harness -----------------------------------------------------------------
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

// --- Flow --------------------------------------------------------------------
require __DIR__ . '/../vendor/autoload.php'; // lädt Core- und Engine-Entry-Point + PSR-4

check('Core-Entry-Point lief (Version angemeldet)', RhBlueprintCoreLoader::pickLatest(['1.0.0']) === '1.0.0');

\RhBackup\Plugin::boot();
check('core/booted-Listener registriert', isset($GLOBALS['__hooks']['rh-blueprint/core/booted']));

do_action('plugins_loaded'); // Negotiation -> Core::boot, hängt init-Hook
do_action('init');           // bootFeatures -> core/booted -> onCoreBooted

check('Core ist gebootet', \RhBlueprint\Core\Core::isBooted());
check('Core-Version passt zum gebundelten Stand', rh_blueprint()->version() === '2.5.0', rh_blueprint()->version());

// --- DB-Engine ---------------------------------------------------------------
check('DB-Engine ist gebootet', \RhDbEngine\DbEngine::isBooted());
$engine = rh_db_engine();
check('Engine liefert Storage', $engine->storage() instanceof \RhDbEngine\Storage);
check('Engine liefert Exporter', $engine->exporter() instanceof \RhDbEngine\Exporter);
check('Engine liefert Importer', $engine->importer() instanceof \RhDbEngine\Importer);

// --- Registrierungen von rh-backup -------------------------------------------
$tabs = rh_blueprint()->settings()->tabs();
check('Backup-Tab registriert', isset($tabs['backup']), 'vorhanden: ' . implode(', ', array_keys($tabs)));

// Erzeugt wird über den Lauf in der Übersicht, nicht mehr über einen eigenen Export.
// Was hier bleibt, sind die Aktionen an einer vorhandenen Sicherung.
check(
    'Handler für Wiederherstellen, Herunterladen und Löschen registriert',
    isset($GLOBALS['__hooks']['admin_post_rhbp_db_import'])
        && isset($GLOBALS['__hooks']['admin_post_rhbp_db_download'])
        && isset($GLOBALS['__hooks']['admin_post_rhbp_db_delete'])
);

check(
    'Der doppelte Weg zum Erstellen ist weg',
    ! isset($GLOBALS['__hooks']['admin_post_rhbp_db_export'])
);

check(
    'Der Lauf von Hand ist erreichbar',
    isset($GLOBALS['__hooks']['admin_post_rhbackup_offsite_run'])
);

check(
    'Meldung über unvollständige Medien wird abgefangen',
    isset($GLOBALS['__hooks']['rh-db-engine/import_incomplete_uploads'])
);

check(
    'Wartungs-Cron eingeplant',
    wp_next_scheduled(\RhBackup\Maintenance::CRON_HOOK) !== false
);

// Der Lauf-Lock (RhDbEngine\JobLock) wird in der db-engine getestet:
//   Code/rh-db-engine/tests/job-lock-test.php
// Hier greift nur der gebundelte vendor-Stand, der erst mit dem nächsten Release nachzieht.

// --- Aufräumen ----------------------------------------------------------------
if (is_dir(WP_CONTENT_DIR)) {
    exec('rm -rf ' . escapeshellarg(WP_CONTENT_DIR));
}

// --- Ergebnis ----------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}

echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
