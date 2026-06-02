<?php

/**
 * Integrationstest für rh-backup + gebundelten Core.
 *
 *   php tests/integration-test.php
 *
 * Beweist den End-to-End-Flow ohne echtes WordPress:
 *   1. vendor/autoload.php lädt den Core-Entry-Point (Composer files-autoload)
 *      -> Core meldet seine Version beim Loader an.
 *   2. Plugin::boot() registriert den core/booted-Listener.
 *   3. plugins_loaded -> Negotiation bootet den Core -> core/booted feuert
 *      -> rh-backup registriert seinen Service + Tools-Tab.
 *   4. Die Backup-API ist über die Service-Registry erreichbar.
 */

declare(strict_types=1);

// --- WP-Stubs ----------------------------------------------------------------
define('ABSPATH', __DIR__ . '/');

$GLOBALS['__hooks'] = [];

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

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '');
}

// --- Harness -----------------------------------------------------------------
$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (! $ok) {
        $failures++;
    }
}

// --- Flow --------------------------------------------------------------------
require __DIR__ . '/../vendor/autoload.php'; // lädt Core-Entry-Point + PSR-4

check('Core-Entry-Point lief (Version angemeldet)', RhBlueprintCoreLoader::pickLatest(['1.0.0']) === '1.0.0');

\RhBackup\Plugin::boot();
do_action('plugins_loaded'); // Negotiation -> Core::boot, hängt init-Hook
do_action('init');           // bootFeatures -> core/booted -> onCoreBooted

check('Core ist gebootet', \RhBlueprint\Core\Core::isBooted());
check('Core-Version 1.1.0 geladen', rh_blueprint()->version() === '1.1.0');
check('Core-Storage verfügbar', rh_blueprint()->storage() instanceof \RhBlueprint\Core\Storage);

$backup = rh_blueprint()->services()->get('backup', 1);
check('backup-Service ist registriert', $backup instanceof \RhBackup\Api);
check('backup apiVersion = 1', $backup !== null && $backup->apiVersion() === 1);
check('backup-Service unter zu hoher minVersion = null', rh_blueprint()->services()->get('backup', 2) === null);

$tabs = rh_blueprint()->settings()->tabs();
check('Tools-Tab registriert', isset($tabs['tools']));
check('Support-Tab (Core-Feature) vorhanden', isset($tabs['support']));
check('Reihenfolge: Support (10) vor Tools (20)', array_keys($tabs) === ['support', 'tools']);

// --- Ergebnis ----------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}

echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
