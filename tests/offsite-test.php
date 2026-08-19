<?php

/**
 * Standalone-Test für das Offsite-Backup nach Google Drive.
 *   php tests/offsite-test.php
 *
 * Getestet wird alles, was ohne echtes Google-Konto prüfbar ist: die Zustandsmaschine
 * des Laufs, die Auswertung der Drive-Antworten (besonders der fortsetzbare Upload) und
 * die Regeln, die verhindern, dass eine Sicherung verloren geht.
 *
 * Der HTTP-Verkehr wird über eine Warteschlange vorgegebener Antworten simuliert.
 */

declare(strict_types=1);

// --- WP-Stubs ----------------------------------------------------------------
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

// Zugangsdaten der Anwendung, wie sie in der wp-config.php stehen wuerden.
define('RH_BACKUP_GDRIVE_CLIENT_ID', 'client-123.apps.googleusercontent.com');
define('RH_BACKUP_GDRIVE_CLIENT_SECRET', 'geheim-456');

$GLOBALS['__options'] = [];
$GLOBALS['__transients'] = [];
$GLOBALS['__http_queue'] = [];
$GLOBALS['__http_log'] = [];
$GLOBALS['__mails'] = [];

$GLOBALS['__hooks'] = [];
$GLOBALS['__cron'] = [];

function add_action(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
    $GLOBALS['__hooks'][$hook][] = $cb;
}
function add_filter(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
    $GLOBALS['__hooks'][$hook][] = $cb;
}
function do_action(string $hook, mixed ...$args): void
{
    foreach ($GLOBALS['__hooks'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}
function __(string $t, string $d = 'default'): string
{
    return $t;
}
function get_option(string $n, mixed $d = false): mixed
{
    return $GLOBALS['__options'][$n] ?? $d;
}
function update_option(string $n, mixed $v, mixed $a = null): bool
{
    $GLOBALS['__options'][$n] = $v;

    return true;
}
function delete_option(string $n): bool
{
    unset($GLOBALS['__options'][$n]);

    return true;
}
function get_transient(string $n): mixed
{
    return $GLOBALS['__transients'][$n] ?? false;
}
function set_transient(string $n, mixed $v, int $t = 0): bool
{
    $GLOBALS['__transients'][$n] = $v;

    return true;
}
function delete_transient(string $n): bool
{
    unset($GLOBALS['__transients'][$n]);

    return true;
}
function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-nur-fuer-diesen-lauf-1234567890';
}
function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}
function sanitize_text_field(string $s): string
{
    return trim(strip_tags($s));
}
function sanitize_file_name(string $s): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '-', $s) ?? $s;
}
function is_email(string $s): bool
{
    return (bool) filter_var($s, FILTER_VALIDATE_EMAIL);
}
function home_url(string $p = ''): string
{
    return 'https://kunde-beispiel.de' . $p;
}
function admin_url(string $p = ''): string
{
    return 'https://kunde-beispiel.de/wp-admin/' . ltrim($p, '/');
}
function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}
function size_format(float|int $bytes, int $dec = 0): string
{
    return number_format((float) $bytes / 1048576, $dec) . ' MB';
}
function wp_date(string $format, ?int $ts = null): string
{
    return gmdate($format, $ts ?? time());
}
function human_time_diff(int $from, int $to = 0): string
{
    return abs(($to ?: time()) - $from) . 's';
}
function wp_delete_file(string $path): void
{
    @unlink($path);
}
function current_user_can(string $cap): bool
{
    return true;
}
function esc_html(string $t): string
{
    return $t;
}
function esc_html__(string $t, string $d = 'default'): string
{
    return $t;
}
function add_query_arg(string $key, string $value, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
}

// Minimaler Cron-Ersatz: ein Termin je Hook, mehr braucht der Zeitplan nicht.
function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['__cron'][$hook] = ['timestamp' => $timestamp, 'schedule' => $recurrence, 'interval' => WEEK_IN_SECONDS];

    return true;
}
function wp_unschedule_event(int $timestamp, string $hook): bool
{
    unset($GLOBALS['__cron'][$hook]);

    return true;
}
function wp_next_scheduled(string $hook): int|false
{
    return isset($GLOBALS['__cron'][$hook]) ? (int) $GLOBALS['__cron'][$hook]['timestamp'] : false;
}
function wp_get_scheduled_event(string $hook): object|false
{
    return isset($GLOBALS['__cron'][$hook]) ? (object) $GLOBALS['__cron'][$hook] : false;
}
function wp_get_schedule(string $hook): string|false
{
    return isset($GLOBALS['__cron'][$hook]) ? (string) $GLOBALS['__cron'][$hook]['schedule'] : false;
}
function trailingslashit(string $s): string
{
    return rtrim($s, '/\\') . '/';
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $value;
}
function wp_mail(string|array $to, string $subject, string $body, array $headers = []): bool
{
    $GLOBALS['__mails'][] = compact('to', 'subject', 'body', 'headers');

    return true;
}
function remove_action(string $hook, callable $cb, int $prio = 10): bool
{
    foreach ($GLOBALS['__hooks'][$hook] ?? [] as $index => $registered) {
        if ($registered === $cb) {
            unset($GLOBALS['__hooks'][$hook][$index]);

            return true;
        }
    }

    return false;
}
function esc_url(string $url): string
{
    return $url;
}
function esc_attr(string $t): string
{
    return $t;
}
function rhbp_setting(string $group, ?string $key = null, mixed $default = null): mixed
{
    $values = $GLOBALS['__options']['rhbp_settings_' . $group] ?? [];

    return $key === null ? $values : ($values[$key] ?? $default);
}
function rhbp_update_setting(string $group, string $key, mixed $value): bool
{
    $GLOBALS['__options']['rhbp_settings_' . $group][$key] = $value;

    return true;
}

/** Legt eine Antwort in die Warteschlange. */
function queue_response(int $status, array $headers = [], mixed $body = ''): void
{
    $GLOBALS['__http_queue'][] = [
        'status' => $status,
        'headers' => $headers,
        'body' => is_string($body) ? $body : (string) json_encode($body),
    ];
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WpErrorStub;
}

final class WpErrorStub
{
    public function __construct(private readonly string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function wp_remote_request(string $url, array $args = []): array|WpErrorStub
{
    $GLOBALS['__http_log'][] = ['url' => $url, 'args' => $args];

    if ($GLOBALS['__http_queue'] === []) {
        return new WpErrorStub('Keine Antwort in der Warteschlange: ' . $url);
    }

    $next = array_shift($GLOBALS['__http_queue']);

    return ['response' => ['code' => $next['status']], 'headers' => $next['headers'], 'body' => $next['body']];
}
function wp_remote_post(string $url, array $args = []): array|WpErrorStub
{
    return wp_remote_request($url, $args);
}
function wp_remote_retrieve_response_code(array $r): int
{
    return (int) ($r['response']['code'] ?? 0);
}
function wp_remote_retrieve_body(array $r): string
{
    return (string) ($r['body'] ?? '');
}

// --- Harness -----------------------------------------------------------------
require __DIR__ . '/../vendor/autoload.php';

// Der Core kommt zur Laufzeit über seinen Version-Negotiation-Loader, nicht über
// diesen Autoloader. Ohne das E-Mail-Modul greift die schlichte Textfassung,
// und genau die soll dieser Test sehen: rh-backup muss auch allein melden können.
// Alles aus dem gebundelten Core, ohne ihn zu starten. Vorher standen hier
// vier Einzel-requires, und die fuenfte Core-Klasse liess den Test sterben.
require __DIR__ . '/../vendor/rh/blueprint-core/autoload-src.php';
require __DIR__ . '/../vendor/rh/tick-engine/autoload-src.php';

// Die db-engine bringt keine solche Datei mit: ihr Loader hängt an plugins_loaded, und
// der Haken fällt hier nie. Ohne das fehlt die Ablage, sobald ein Test Markup rendert.
spl_autoload_register(static function (string $class): void {
    $prefix = 'RhDbEngine\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $datei = __DIR__ . '/../vendor/rh/db-engine/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($datei)) {
        require_once $datei;
    }
});

use RhBackup\Offsite\Connection;
use RhBackup\Offsite\ExpiredSessionError;
use RhBackup\Offsite\GoogleDrive;
use RhBackup\Offsite\HttpResponse;
use RhBackup\Offsite\Secret;
use RhBackup\Offsite\Settings;
use RhBackup\Offsite\TransientUploadError;
use RhBackup\Offsite\UploadJob;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}
function section(string $title): void
{
    echo "\n" . $title . "\n";
}

// --- 1. Verschlüsselung -------------------------------------------------------

section('Geheimnisse');

if (! Secret::available()) {
    check('libsodium vorhanden', false, 'ohne Sodium wird nichts verschlüsselt');
} else {
    $token = '1//0abcdefgh-REFRESH-TOKEN-xyz';
    $stored = Secret::encrypt($token);
    check('Verschlüsselt gespeichert, nicht im Klartext', $stored !== '' && ! str_contains($stored, 'REFRESH'));
    check('Entschlüsselung liefert das Original', Secret::decrypt($stored) === $token);
    check('Zwei Durchläufe ergeben verschiedene Chiffren', Secret::encrypt($token) !== $stored);
    check('Kaputte Eingabe ergibt einen leeren Wert', Secret::decrypt('kein-base64!!') === '');
    check('Leere Eingabe ergibt einen leeren Wert', Secret::decrypt('') === '');
}

// --- 2. Einstellungen ---------------------------------------------------------

section('Einstellungen');

check('Standard sind zwölf Kopien', Settings::keepCopies() === 12);
rhbp_update_setting('offsite', 'keep_copies', 0);
check('Null Kopien wird auf eine angehoben, nie alles löschen', Settings::keepCopies() === 1);
rhbp_update_setting('offsite', 'keep_copies', 9999);
check('Absurd hoher Wert wird gedeckelt', Settings::keepCopies() === 365);
rhbp_update_setting('offsite', 'keep_copies', 5);
check('Gültiger Wert bleibt stehen', Settings::keepCopies() === 5);

check('Standard-Ordner heisst Website-Backups', Settings::folderName() === 'Website-Backups');
check('Unterordner ist die Domain', Settings::siteFolderName() === 'kunde-beispiel.de');

rhbp_update_setting('offsite', 'interval', 'gibt-es-nicht');
check('Unbekannter Zeitplan fällt auf den Standard zurück', Settings::interval() === 'rhbackup_monthly');

$GLOBALS['__options']['admin_email'] = 'chef@kunde-beispiel.de';
check('Ohne eigene Adresse die des Administrators', Settings::notifyEmail() === 'chef@kunde-beispiel.de');
rhbp_update_setting('offsite', 'notify_email', 'keine-echte-adresse');
check('Ungültige Adresse fällt auf den Administrator zurück', Settings::notifyEmail() === 'chef@kunde-beispiel.de');

// --- 3. Antwort-Auswertung ----------------------------------------------------

section('HTTP-Antworten');

$transient = HttpResponse::fromWp(['response' => ['code' => 503], 'headers' => [], 'body' => '']);
check('503 gilt als vorübergehend', $transient->isTransient());

$rate = HttpResponse::fromWp([
    'response' => ['code' => 403],
    'headers' => [],
    'body' => (string) json_encode(['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]]),
]);
check('403 wegen Rate-Limit gilt als vorübergehend', $rate->isTransient());

$denied = HttpResponse::fromWp([
    'response' => ['code' => 403],
    'headers' => [],
    'body' => (string) json_encode(['error' => ['errors' => [['reason' => 'insufficientPermissions']]]]),
]);
check('403 wegen fehlender Rechte ist endgültig', ! $denied->isTransient());

$notFound = HttpResponse::fromWp(['response' => ['code' => 404], 'headers' => [], 'body' => '']);
check('404 ist endgültig', ! $notFound->isTransient());

$retryAfter = HttpResponse::fromWp(['response' => ['code' => 429], 'headers' => ['Retry-After' => '42'], 'body' => '']);
check('Vorgegebene Wartezeit wird gelesen', $retryAfter->retryAfter() === 42);
check('Gross- und Kleinschreibung im Header egal', $retryAfter->header('retry-after') === '42');

$netErr = HttpResponse::fromNetworkError('timeout');
check('Netzwerkfehler gilt als vorübergehend', $netErr->isTransient());
check('Netzwerkfehler nennt den Grund', $netErr->errorMessage() === 'timeout');

// --- 4. Zustand des Laufs -----------------------------------------------------

section('Zustand des Laufs');

$job = UploadJob::create('manual');
check('Kennung ist 32 Zeichen lang', (bool) preg_match('/^[a-f0-9]{32}$/', $job->jobId));
check('Merkmal für den Hintergrund-Aufruf ist gesetzt', strlen($job->spawnToken) === 32);
check('Erste Phase ist der Export', $job->phase === UploadJob::PHASE_EXPORT);
check('Zeitbudget liegt im sinnvollen Bereich', $job->tickBudget >= 5.0 && $job->tickBudget <= 20.0, (string) $job->tickBudget);

$reloaded = UploadJob::loadFor($job->jobId);
check('Laden über die Kennung klappt', $reloaded !== null && $reloaded->jobId === $job->jobId);
check('Fremde Kennung lädt nichts', UploadJob::loadFor(str_repeat('a', 32)) === null);
check('Unsinnige Kennung lädt nichts', UploadJob::loadFor('../../etc/passwd') === null);

$roundtrip = UploadJob::fromArray($job->toArray());
check('Zustand übersteht das Speichern und Laden', $roundtrip->toArray() === $job->toArray());

$job->chunkSize = UploadJob::CHUNK_SIZE;
$aligned = true;
$steps = 0;
while ($job->reduceChunkSize() && $steps++ < 20) {
    if ($job->chunkSize % UploadJob::MIN_CHUNK_SIZE !== 0) {
        $aligned = false;
        break;
    }
}
check('Abschnittsgrösse bleibt ein Vielfaches von 256 KB', $aligned, (string) $job->chunkSize);
check('Verkleinern endet bei der Mindestgrösse', $job->chunkSize === UploadJob::MIN_CHUNK_SIZE);
check('An der Mindestgrösse geht es nicht weiter', $job->reduceChunkSize() === false);

$job2 = UploadJob::create('manual');
$before = time();
check('Erster Rückzug wird eingeplant', $job2->scheduleRetry());
check('Frist liegt in der Zukunft', $job2->retryAfter > $before);
check('Lauf wartet die Frist ab', $job2->isWaiting());
$firstDelay = $job2->retryAfter - $before;
$job2->retryAfter = 0;
$job2->scheduleRetry();
$secondDelay = $job2->retryAfter - time();
check('Zweiter Rückzug wartet länger als der erste', $secondDelay >= $firstDelay, "{$firstDelay} dann {$secondDelay}");

$job3 = UploadJob::create('manual');
for ($i = 0; $i < UploadJob::MAX_RETRIES; $i++) {
    $job3->scheduleRetry();
}
check('Nach dem letzten Versuch ist Schluss', $job3->scheduleRetry() === false);

// --- 5. Fortsetzbarer Upload --------------------------------------------------

section('Fortsetzbarer Upload');

$GLOBALS['__options'] = [];
$connection = new Connection();
$connection->storeRefreshToken('refresh-abc');
$connection->storeAccessToken('access-abc', 3600);
$drive = new GoogleDrive($connection);

queue_response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=session-1'], '');
$session = $drive->startUploadSession('backup.zip', 1000000, 'ordner-1');
check('Adresse der Sitzung kommt aus dem Location-Header', $session === 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=session-1');

// An diese Adresse geht anschliessend das komplette Backup. Zeigt sie nicht auf Google,
// wird der Lauf abgebrochen, statt die Daten irgendwohin zu schicken.
$abgelehnt = false;
try {
    queue_response(200, ['Location' => 'https://evil.example.com/session'], '');
    $drive->startUploadSession('backup.zip', 1000, 'ordner-1');
} catch (\RuntimeException $e) {
    $abgelehnt = true;
}
check('Upload-Adresse ausserhalb von Google wird abgelehnt', $abgelehnt);

$abgelehnt = false;
try {
    $drive->uploadChunk('https://evil.example.com/session', 'x', 0, 1000);
} catch (\RuntimeException $e) {
    $abgelehnt = true;
}
check('Auch eine gespeicherte fremde Adresse wird abgelehnt', $abgelehnt);

queue_response(308, ['Range' => 'bytes=0-262143'], '');
$result = $drive->uploadChunk($session, str_repeat('x', 262144), 0, 1000000);
check('308 bedeutet noch nicht fertig', $result['done'] === false);
check('Nächste Position kommt aus dem Range-Header', $result['next_offset'] === 262144, (string) $result['next_offset']);

queue_response(308, [], '');
$result = $drive->uploadChunk($session, 'abc', 0, 1000000);
check('308 ohne Range bedeutet Position null', $result['next_offset'] === 0);

queue_response(200, [], ['id' => 'datei-1', 'size' => '1000000']);
$result = $drive->uploadChunk($session, 'rest', 999996, 1000000);
check('200 bedeutet fertig', $result['done'] === true);
check('Kennung der Datei wird übernommen', $result['file_id'] === 'datei-1');

queue_response(404, [], '');
$expired = false;
try {
    $drive->uploadChunk($session, 'abc', 0, 1000000);
} catch (ExpiredSessionError) {
    $expired = true;
}
check('404 meldet die abgelaufene Sitzung eigens', $expired);

queue_response(503, ['Retry-After' => '7'], '');
$transientHit = null;
try {
    $drive->uploadChunk($session, 'abc', 0, 1000000);
} catch (TransientUploadError $e) {
    $transientHit = $e;
}
check('503 wird als vorübergehend gemeldet', $transientHit !== null);
check('Vorgeschlagene Wartezeit wird durchgereicht', $transientHit?->retryAfter() === 7);

queue_response(400, [], ['error' => ['message' => 'Ungültige Anfrage']]);
$permanent = false;
try {
    $drive->uploadChunk($session, 'abc', 0, 1000000);
} catch (ExpiredSessionError | TransientUploadError) {
    $permanent = false;
} catch (\RuntimeException) {
    $permanent = true;
}
check('400 beendet den Lauf endgültig', $permanent);

queue_response(308, ['Range' => 'bytes=0-524287'], '');
$status = $drive->queryUploadStatus($session, 1000000);
check('Nachfrage liefert die tatsächliche Position', $status['next_offset'] === 524288);

// --- 6. Anmeldung -------------------------------------------------------------

section('Anmeldung über den Geräte-Ablauf');

// Sicherheitsnetz gegen versehentliches Committen: die Werte gehören ins
// Release-Paket, nicht ins öffentliche Repository.
$credentialsFile = (string) file_get_contents(__DIR__ . '/../inc/Offsite/Credentials.php');
check(
    'Zugangsdaten stehen NICHT im Repository',
    str_contains($credentialsFile, "public const CLIENT_ID = '';")
        && str_contains($credentialsFile, "public const CLIENT_SECRET = '';"),
    'Credentials.php enthält Werte, die dort nicht hingehören'
);

check('Zugangsdaten der Anwendung sind hinterlegt', Settings::hasOAuthClient());
check('Client-ID kommt aus der Konstante', Settings::clientId() === 'client-123.apps.googleusercontent.com');
check(
    'Zugangsdaten stehen nicht in den Einstellungen der Website',
    ! array_key_exists('client_id', (array) rhbp_setting('offsite'))
        && ! array_key_exists('client_secret', (array) rhbp_setting('offsite')),
    implode(', ', array_keys((array) rhbp_setting('offsite')))
);

queue_response(200, [], [
    'device_code' => 'device-abc',
    'user_code' => 'BDWD-HQPK',
    'verification_url' => 'https://www.google.com/device',
    'expires_in' => 1800,
    'interval' => 5,
]);
$device = $drive->requestDeviceCode();
check('Kurzer Code für den Nutzer', $device['user_code'] === 'BDWD-HQPK');
check('Adresse zum Eingeben', $device['verification_url'] === 'https://www.google.com/device');

$lastCall = end($GLOBALS['__http_log']);
check(
    'Angefordert wird ausschliesslich der Zugriff auf eigene Dateien',
    ($lastCall['args']['body']['scope'] ?? '') === 'https://www.googleapis.com/auth/drive.file',
    (string) ($lastCall['args']['body']['scope'] ?? '')
);

queue_response(428, [], ['error' => 'authorization_pending']);
check('Warten auf die Bestätigung wird erkannt', $drive->pollDeviceCode('device-abc')['status'] === 'pending');

queue_response(403, [], ['error' => 'slow_down']);
check('Zu häufiges Nachfragen wird erkannt', $drive->pollDeviceCode('device-abc')['status'] === 'slow_down');

queue_response(403, [], ['error' => 'access_denied']);
check('Ablehnung wird erkannt', $drive->pollDeviceCode('device-abc')['status'] === 'denied');

queue_response(200, [], ['refresh_token' => 'refresh-neu', 'access_token' => 'access-neu', 'expires_in' => 3599]);
$connected = $drive->pollDeviceCode('device-abc');
check('Freigabe liefert den dauerhaften Zugang', $connected['status'] === 'connected' && $connected['refresh_token'] === 'refresh-neu');

queue_response(401, [], ['error' => 'invalid_client']);
$wrongClient = $drive->pollDeviceCode('device-abc');
check(
    'Falscher Client-Typ wird verständlich gemeldet',
    str_contains((string) ($wrongClient['message'] ?? ''), 'TVs and Limited Input devices')
);

// --- 7. Verbindung ------------------------------------------------------------

section('Verbindung');

$GLOBALS['__options'] = [];
$fresh = new Connection();
check('Ohne Zugang gilt sie als nicht verbunden', ! $fresh->isConnected());
$fresh->storeRefreshToken('refresh-xyz', 'kunde@gmail.com');
check('Nach dem Speichern verbunden', $fresh->isConnected());
check('Konto wird gemerkt', $fresh->account() === 'kunde@gmail.com');
check(
    'Zugang liegt nicht im Klartext in der Datenbank',
    ! str_contains((string) ($GLOBALS['__options'][Settings::OPTION_REFRESH_TOKEN] ?? ''), 'refresh-xyz')
);
$fresh->disconnect();
check('Trennen entfernt den Zugang', ! $fresh->isConnected());
check('Trennen entfernt auch den gemerkten Ordner', $fresh->folderId() === '');

// --- 8. Rotation --------------------------------------------------------------

section('Rotation');

$files = [];
for ($i = 1; $i <= 15; $i++) {
    $files[] = ['id' => 'datei-' . $i, 'name' => 'b' . $i . '.zip', 'size' => 100, 'created' => ''];
}
$neueId = 'datei-1';
$keep = 12;
$obsolete = array_slice(
    array_values(array_filter($files, static fn (array $f): bool => $f['id'] !== $neueId)),
    max(0, $keep - 1)
);
check('Die frische Datei steht nie auf der Löschliste', ! in_array($neueId, array_column($obsolete, 'id'), true));
check('Es bleiben genau zwölf Kopien übrig', count($files) - count($obsolete) === 12, (string) (count($files) - count($obsolete)));

$wenige = array_slice($files, 0, 3);
$obsolete = array_slice(
    array_values(array_filter($wenige, static fn (array $f): bool => $f['id'] !== $neueId)),
    max(0, $keep - 1)
);
check('Unter der Grenze wird nichts gelöscht', $obsolete === []);

// --- 9. Benachrichtigung ------------------------------------------------------

section('Benachrichtigung');

$GLOBALS['__options'] = [];
$GLOBALS['__options']['admin_email'] = 'chef@kunde-beispiel.de';
$GLOBALS['__mails'] = [];

$failed = UploadJob::create('schedule');
$failed->totalSize = 5000000;
$failed->offset = 1000000;
$failed->phase = UploadJob::PHASE_UPLOAD;
$failed->finishFailure('Der Zugang zu Google Drive gilt nicht mehr.');
check('Gescheiterte Phase wird festgehalten', $failed->failedPhase === UploadJob::PHASE_UPLOAD, $failed->failedPhase);
(new \RhBackup\Offsite\Notifier())->reportFailure($failed);

check('Eine Mail geht raus', count($GLOBALS['__mails']) === 1);
$mail = $GLOBALS['__mails'][0] ?? null;
check('An die Adresse des Administrators', ($mail['to'] ?? '') === 'chef@kunde-beispiel.de');
check('Betreff nennt die Domain', str_contains((string) ($mail['subject'] ?? ''), 'kunde-beispiel.de'));
check('Text nennt den Grund', str_contains((string) ($mail['body'] ?? ''), 'gilt nicht mehr'));
check(
    'Text nennt den Schritt, an dem es scheiterte',
    str_contains((string) ($mail['body'] ?? ''), 'Übertragung'),
    (string) ($mail['body'] ?? '')
);
check('Text stellt klar, dass nichts gelöscht wurde', str_contains((string) ($mail['body'] ?? ''), 'unverändert'));

$lastRun = get_option(Settings::OPTION_LAST_RUN);
check('Fehlschlag wird für die Anzeige festgehalten', is_array($lastRun) && $lastRun['success'] === false);

// --- 10. Wächter --------------------------------------------------------------

section('Wächter bei langen Läufen');

$GLOBALS['__options'] = [];
$GLOBALS['__mails'] = [];
add_filter('rh-backup/offsite/suppress_loopback', static fn () => true);

/**
 * Setzt den Lauf in den Zustand, in dem der Wächter zuständig ist: stehengeblieben, und
 * in einer Rückzugsfrist, damit der Tick nur weiterreicht statt echte Arbeit zu tun.
 */
function staleMachen(UploadJob $job): void
{
    $job->retryAfter = time() + 600;
    $job->save();
    $daten = get_option(UploadJob::OPTION);
    $daten['last_update_at'] = time() - (UploadJob::STALE_AFTER + 60);
    update_option(UploadJob::OPTION, $daten, false);
}

$advancer = (new ReflectionClass(\RhBackup\Offsite\UploadAdvancer::class))->newInstanceWithoutConstructor();
$runner = new \RhBackup\Offsite\UploadRunner($advancer, new \RhBackup\Offsite\Notifier());

// Ein langer, aber gesunder Lauf: der Loopback fällt aus, der Wächter treibt an, und der
// Übertragungs-Stand wächst mit jeder Runde. Das darf nie zum Abbruch führen.
$job = UploadJob::create('schedule');
$job->phase = UploadJob::PHASE_UPLOAD;
$job->totalSize = 900 * 1024 * 1024;

for ($runde = 1; $runde <= 20; $runde++) {
    $job->offset += 8 * 1024 * 1024;
    staleMachen($job);
    $runner->runWatchdog();
    $job = UploadJob::load();
}

check(
    'Ein Lauf mit Fortschritt überlebt 20 Wächter-Runden',
    $job !== null && $job->phase === UploadJob::PHASE_UPLOAD,
    $job === null ? 'kein Job' : $job->phase . ' / ' . $job->error
);
check('Dabei geht keine Fehlermeldung raus', count($GLOBALS['__mails']) === 0);

// Derselbe Wächter, aber der Stand bewegt sich nicht mehr. Genau dafür ist der Deckel da.
$GLOBALS['__options'] = ['admin_email' => 'chef@kunde-beispiel.de'];
$GLOBALS['__mails'] = [];

$job = UploadJob::create('schedule');
$job->phase = UploadJob::PHASE_UPLOAD;
$job->offset = 4096;

for ($runde = 1; $runde <= 8; $runde++) {
    staleMachen($job);
    $runner->runWatchdog();
    $job = UploadJob::load();
    if ($job === null || $job->isFinished()) {
        break;
    }
}

check(
    'Ein wirklich stehengebliebener Lauf wird abgebrochen',
    $job !== null && $job->phase === UploadJob::PHASE_FAILED,
    $job === null ? 'kein Job' : $job->phase
);
check('Der Abbruch wird gemeldet', count($GLOBALS['__mails']) === 1);
check(
    'Der Zähler wird dabei aufgeräumt',
    get_option('rhbackup_offsite_revivals', null) === null
);

// Das Kennzeichen muss auf alles reagieren, was Fortschritt bedeutet, nicht nur auf den
// Upload. Sonst gilt die lange Export-Phase als Stillstand.
$a = UploadJob::create('manual');
$vorher = $a->progressMark();
$a->exportCursor = ['phase' => 'sql', 'table_index' => 3, 'row_offset' => 1500];
check('Fortschritt im Export ändert das Kennzeichen', $a->progressMark() !== $vorher);

$nachExport = $a->progressMark();
$a->phase = UploadJob::PHASE_UPLOAD;
$a->offset = 8 * 1024 * 1024;
check('Fortschritt beim Hochladen ändert das Kennzeichen', $a->progressMark() !== $nachExport);

$stillstand = $a->progressMark();
$a->message = 'nur ein neuer Text';
check('Reiner Textwechsel gilt nicht als Fortschritt', $a->progressMark() === $stillstand);

// --- 11. Verlässliche Läufe ---------------------------------------------------

section('Ping-Endpunkt');

$GLOBALS['__options'] = [];

use RhBackup\Cron\CronHealth;
use RhBackup\Cron\PingEndpoint;
use RhBackup\Offsite\Scheduler;

check('Ohne Merkmal ist der Endpunkt aus', PingEndpoint::token() === '' && PingEndpoint::url() === '');

$token = PingEndpoint::ensureToken();
check('Das Merkmal ist lang genug zum Nichterraten', strlen($token) === 32);
check('Es wird nicht bei jedem Aufruf neu erzeugt', PingEndpoint::ensureToken() === $token);
check('Neuerzeugen ändert es wirklich', PingEndpoint::regenerateToken() !== $token);
check('Die Adresse enthält das Merkmal', str_contains(PingEndpoint::url(), PingEndpoint::token()));

// Der Termin darf nur einmal beansprucht werden, sonst startet jeder weitere Aufruf
// erneut, solange er überfällig ist.
$GLOBALS['__cron'] = [];
check('Ohne Termin gibt es nichts zu tun', Scheduler::claimDueRun() === false);

wp_schedule_event(time() + 3600, 'weekly', Scheduler::RUN_HOOK);
check('Ein Termin in der Zukunft wird nicht beansprucht', Scheduler::claimDueRun() === false);

$GLOBALS['__cron'] = [];
wp_schedule_event(time() - 7200, 'weekly', Scheduler::RUN_HOOK);
check('Ein überfälliger Termin wird beansprucht', Scheduler::claimDueRun() === true);
check('Und danach kein zweites Mal', Scheduler::claimDueRun() === false);
check('Der nächste Termin liegt wieder in der Zukunft', (int) Scheduler::nextRun() > time());

section('Selbstprüfung der Zeitsteuerung');

$GLOBALS['__options'] = ['admin_email' => 'chef@kunde-beispiel.de'];
$GLOBALS['__mails'] = [];
$GLOBALS['__cron'] = [];

// Der Options-Reset hat auch das Merkmal gelöscht. Ohne neues wäre die Prüfung weiter
// unten auf den Inhalt der Mail wertlos, weil sie gegen eine leere Zeichenkette liefe.
PingEndpoint::ensureToken();

$health = new CronHealth(new \RhBackup\Offsite\Notifier());

// Ohne Verbindung gibt es keinen Zeitplan, über den man sich sorgen müsste.
wp_schedule_event(time() - 10 * HOUR_IN_SECONDS, 'weekly', Scheduler::RUN_HOOK);
$health->maybeCheck();
check('Ohne Verbindung schlägt nichts an', CronHealth::problems() === []);

$connection = new Connection();
$connection->storeRefreshToken('refresh-xyz', 'kunde@gmail.com');

/**
 * Setzt die Drosselung zurück, damit die nächste Prüfung sofort läuft.
 */
function pruefungFreigeben(): void
{
    $state = get_option(CronHealth::OPTION_STATE, []);
    $state['checked_at'] = 0;
    update_option(CronHealth::OPTION_STATE, $state, false);
}

// Der Lauf ohne Verbindung hat die Drosselung schon gesetzt.
pruefungFreigeben();
$health->maybeCheck();
check('Der überfällige Termin wird erkannt', CronHealth::problems() !== []);
check('Beim ersten Mal geht noch keine Mail raus', count($GLOBALS['__mails']) === 0);

$health->maybeCheck();
check('Die Drosselung verhindert eine zweite Prüfung in derselben Stunde', count($GLOBALS['__mails']) === 0);

pruefungFreigeben();
$health->maybeCheck();
check('Auch beim zweiten Mal noch keine Mail', count($GLOBALS['__mails']) === 0);

pruefungFreigeben();
$health->maybeCheck();
check('Beim dritten Mal geht die Mail raus', count($GLOBALS['__mails']) === 1);

$cronMail = $GLOBALS['__mails'][0] ?? [];
check('Die Mail nennt den Aufruf als Abhilfe', str_contains((string) ($cronMail['body'] ?? ''), 'curl -s'));
check(
    'Die Mail nennt das Merkmal, sonst hilft der Befehl nichts',
    str_contains((string) ($cronMail['body'] ?? ''), PingEndpoint::token())
);

pruefungFreigeben();
$health->maybeCheck();
check('Es bleibt bei einer Mail, solange sich nichts ändert', count($GLOBALS['__mails']) === 1);

// Termin wieder in Ordnung: die Zählung muss zurückgehen, sonst meldet sich die
// Prüfung nie wieder, wenn es später erneut hakt.
$GLOBALS['__cron'] = [];
wp_schedule_event(time() + 3 * DAY_IN_SECONDS, 'weekly', Scheduler::RUN_HOOK);
update_option(Settings::OPTION_LAST_RUN, ['time' => time(), 'success' => true], false);
pruefungFreigeben();
$health->maybeCheck();
check('Ist alles in Ordnung, verschwindet die Meldung', CronHealth::problems() === []);

$GLOBALS['__cron'] = [];
wp_schedule_event(time() - 10 * HOUR_IN_SECONDS, 'weekly', Scheduler::RUN_HOOK);
for ($i = 0; $i < 3; $i++) {
    pruefungFreigeben();
    $health->maybeCheck();
}
check('Und beim nächsten Ausfall wird wieder gemeldet', count($GLOBALS['__mails']) === 2);

// --- 12. Ablage-Modi ----------------------------------------------------------

section('Wo die Sicherungen liegen');

use RhBackup\Storage\BackupKind;

$GLOBALS['__options'] = [];
$GLOBALS['__cron'] = [];

check('Ohne Einstellung liegt alles lokal', Settings::mode() === Settings::MODE_LOCAL);
check('Und lokale Kopien bleiben erlaubt', Settings::keepsLocalCopies());

// Der Drive-Modus ohne verbundenes Konto wäre ein Zustand, in dem gar nicht gesichert
// wird: das Archiv geht nirgendwohin und bleibt auch nicht hier.
rhbp_update_setting(Settings::GROUP, Settings::MODE, Settings::MODE_DRIVE);
check('Drive ohne Verbindung fällt auf lokal zurück', Settings::mode() === Settings::MODE_LOCAL);

(new Connection())->storeRefreshToken('refresh-xyz', 'kunde@gmail.com');
check('Mit Verbindung gilt der Drive-Modus', Settings::mode() === Settings::MODE_DRIVE);
check('Dann bleibt hier nichts liegen', ! Settings::keepsLocalCopies());

$driveJob = UploadJob::create('schedule');
check('Der Lauf merkt sich den Modus', $driveJob->mode === Settings::MODE_DRIVE);
check('Und weiss, dass er nicht lokal ist', ! $driveJob->isLocalMode());

// Der Modus wird beim Start festgehalten: wird er mitten im Lauf umgestellt, würde ein
// halb hochgeladenes Archiv sonst plötzlich lokal abgelegt.
rhbp_update_setting(Settings::GROUP, Settings::MODE, Settings::MODE_LOCAL);
$wieder = UploadJob::load();
check(
    'Ein Wechsel während des Laufs ändert ihn nicht mehr',
    $wieder !== null && $wieder->mode === Settings::MODE_DRIVE
);

$neu = UploadJob::create('manual');
check('Ein neuer Lauf nimmt den aktuellen Modus', $neu->mode === Settings::MODE_LOCAL);

section('Anlass einer Sicherung');

check('Der Zeitplan sichert planmässig', UploadJob::create('schedule')->kind() === BackupKind::AUTOMATIC);
check('Alles andere gilt als von Hand', UploadJob::create('manual')->kind() === BackupKind::MANUAL);

check('Anlass aus dem Pfad: planmässig', BackupKind::fromPath('automatic/backup-x.zip') === BackupKind::AUTOMATIC);
check('Anlass aus dem Pfad: vor einem Sync', BackupKind::fromPath('presync/backup-x.zip') === BackupKind::PRESYNC);
check('Flach abgelegtes bleibt ohne Zuordnung', BackupKind::fromPath('backup-x.zip') === BackupKind::LEGACY);
check('Ein fremder Ordner zählt nicht als Anlass', BackupKind::fromPath('irgendwas/backup-x.zip') === BackupKind::LEGACY);
check('Jeder Anlass hat eine Bezeichnung', BackupKind::label(BackupKind::PRESYNC) !== BackupKind::PRESYNC);
check('Auch das ohne Zuordnung', BackupKind::label(BackupKind::LEGACY) !== '');

// Von Sicherungskopien braucht es weniger Stände als von planmässigen: sie entstehen bei
// jedem Sync und wären sonst die einzigen, die noch da sind.
check(
    'Sicherungskopien werden kürzer aufbewahrt als planmässige',
    BackupKind::defaultKeep(BackupKind::PRESYNC) < BackupKind::defaultKeep(BackupKind::AUTOMATIC)
);

// --- 13. Der Weg von der Platte zu Google Drive --------------------------------

/*
 * Warum dieser Abschnitt existiert:
 *
 * Bis 0.5.3 gab es keinen. Das Feld "In Google Drive" ist gesperrt, solange kein Konto
 * verbunden ist. Verbunden wird nur in der Verbindungskarte, die Karte steht nur im
 * Fenster hinter dem Zahnrad, und das Zahnrad stand nur da, wenn Drive bereits galt.
 * Eine geschlossene Schleife: keine Installation konnte je verbinden.
 *
 * Der Test prüft deshalb nicht eine einzelne Methode, sondern die Erreichbarkeit im
 * fertigen Markup der Übersicht: Öffner vorhanden, Fenster vorhanden, Verbinden-Knopf
 * darin. Fällt eines davon weg, ist die Schleife zurück.
 */

section('Von der Platte zu Google Drive');

use RhBackup\Admin\BackupTabs;
use RhBackup\Admin\OffsitePage;
use RhBackup\Offsite\UploadRunner;
use RhBackup\Storage\StoreRegistry;
use RhBackup\Storage\TransferRunner;
use RhDbEngine\Storage;

// Kennungen aus dem Markup. Sie sind der Vertrag zwischen Öffner und Fenster, den die
// Mechanik des Core auswertet, deshalb stehen sie hier wörtlich.
const MODAL_STORE_ID = 'rhbp-modal-backup-store';

define('WP_CONTENT_DIR', sys_get_temp_dir() . '/rhbackup-test-content');

// Stubs, die nur die Ausgabe braucht.
function esc_attr__(string $t, string $d = 'default'): string
{
    return $t;
}
function esc_js(string $t): string
{
    return addslashes($t);
}
function esc_textarea(string $t): string
{
    return $t;
}
function _n(string $single, string $plural, int $number, string $d = 'default'): string
{
    return $number === 1 ? $single : $plural;
}
function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
}
function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . md5($action);
}
function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    $feld = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '" />';
    if ($echo) {
        echo $feld;
    }

    return $feld;
}
function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return __checked_selected_helper($a, $b, $echo, 'checked');
}
function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return __checked_selected_helper($a, $b, $echo, 'selected');
}
function disabled(mixed $a, mixed $b = true, bool $echo = true): string
{
    return __checked_selected_helper($a, $b, $echo, 'disabled');
}
function __checked_selected_helper(mixed $a, mixed $b, bool $echo, string $type): string
{
    $out = (string) $a === (string) $b ? " {$type}='{$type}'" : '';
    if ($echo) {
        echo $out;
    }

    return $out;
}
function wp_print_inline_script_tag(string $js, array $attr = []): void
{
    echo '<script>' . $js . '</script>';
}
function number_format_i18n(float $number, int $decimals = 0): string
{
    return number_format($number, $decimals);
}

/**
 * Die Übersicht, so wie sie im Browser ankommt.
 *
 * Die beiden Läufer gehen die Ausgabe nichts an, sie kommen deshalb ohne Konstruktor:
 * ihre eigenen Abhängigkeiten (Exporter, Importer, wpdb) hätten in diesem Test nichts
 * zu tun. Alles, was die Ausgabe wirklich liest, ist echt.
 */
function uebersicht_markup(): string
{
    $connection = new Connection();
    $drive = new GoogleDrive($connection);
    $stores = new StoreRegistry(new Storage(), $drive, $connection);

    $runner = (new ReflectionClass(UploadRunner::class))->newInstanceWithoutConstructor();
    $transfer = (new ReflectionClass(TransferRunner::class))->newInstanceWithoutConstructor();

    $page = new OffsitePage($connection, $drive, $runner, $stores, $transfer);

    ob_start();
    $page->renderPane(BackupTabs::PANE_OVERVIEW);

    return (string) ob_get_clean();
}

$GLOBALS['__options'] = [];
$GLOBALS['__transients'] = [];
$GLOBALS['__cron'] = [];
$_GET = [];

$markup = uebersicht_markup();

check('Ohne Konto gilt die Platte', Settings::mode() === Settings::MODE_LOCAL);
check(
    'Und das Feld für Drive ist gesperrt',
    (bool) preg_match('/value=.drive.[^>]*disabled/', $markup)
);

// Der Kern: das Zahnrad steht auch da, solange die Platte gilt.
$oeffner = strpos($markup, 'data-rhbp-modal-open="' . MODAL_STORE_ID . '"');
$fenster = strpos($markup, 'id="' . MODAL_STORE_ID . '"');
$verbinden = strpos($markup, 'value="rhbackup_offsite_connect"');

check('Das Zahnrad des Ablageorts steht auch bei lokaler Ablage da', $oeffner !== false);
check('Das Fenster dazu wird ausgegeben', $fenster !== false);
check('Der Verbinden-Knopf steht darin', $verbinden !== false && $fenster !== false && $verbinden > $fenster);
check(
    'Es gibt keine zweite Stelle zum Verbinden, die den Test bestehen liesse',
    substr_count($markup, 'value="rhbackup_offsite_connect"') === 1
);

// Der Geräte-Code steht in derselben Karte. Nach dem Klick führt eine Weiterleitung
// zurück auf die Seite, das Fenster wäre wieder zu: dann stünde in der Meldung ein
// Code zu bestätigen, den niemand sieht.
$_GET = ['rhbp_message' => 'offsite_pending'];
set_transient('rhbackup_offsite_pending', ['user_code' => 'ABCD-EFGH', 'verification_url' => 'https://www.google.com/device', 'expires_in' => 900], 900);

$markupPending = uebersicht_markup();

check('Der Geräte-Code steht im Markup', str_contains($markupPending, 'ABCD-EFGH'));
check(
    'Und das Fenster geht dafür von selbst wieder auf',
    str_contains($markupPending, '"' . MODAL_STORE_ID . '"') && str_contains($markupPending, 'oeffner.click()')
);

delete_transient('rhbackup_offsite_pending');
$_GET = [];

// Mit verbundenem Konto ist der Ort wählbar, und die Karte zeigt das Konto.
(new Connection())->storeRefreshToken('refresh-xyz', 'kunde@gmail.com');

// Was in Drive liegt, kommt aus dem Zwischenspeicher: sonst fragt die Übersicht beim
// Aufbau nach den verstreuten Sicherungen wirklich bei Google nach. Dieser Test misst
// Markup, nicht das Netz.
set_transient('rhbackup_drive_list', [], 120);

$markupVerbunden = uebersicht_markup();

check(
    'Mit verbundenem Konto ist das Feld für Drive nicht mehr gesperrt',
    ! preg_match('/value=.drive.[^>]*disabled/', $markupVerbunden)
);
check('Und das Zahnrad bleibt', str_contains($markupVerbunden, 'data-rhbp-modal-open="' . MODAL_STORE_ID . '"'));
check('Das verbundene Konto steht im Fenster', str_contains($markupVerbunden, 'kunde@gmail.com'));

// --- Ergebnis ----------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}

echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
