<?php

/**
 * Standalone-Test für die Härtungen nach dem Sicherheits- und Leistungs-Durchgang.
 *   php tests/hardening-test.php
 *
 * Geprüft werden die Punkte, die sich ohne WordPress nachstellen lassen: die Adresse,
 * an die das Backup hochgeladen wird, der Dateiname im Download-Header und die
 * Abschnittsgrösse, die sich am Speicherlimit orientieren soll.
 */

declare(strict_types=1);

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

// --- Upload-Adresse -----------------------------------------------------------

// Nachbau von GoogleDrive::isGoogleUploadUrl. An diese Adresse geht das komplette
// Backup, sie kommt aus einem Antwort-Header und wandert in den Job-Zustand.
function istGoogleUploadUrl(string $url): bool
{
    $teile = parse_url($url);

    if (! is_array($teile) || ($teile['scheme'] ?? '') !== 'https') {
        return false;
    }

    $host = strtolower((string) ($teile['host'] ?? ''));

    return $host === 'googleapis.com' || str_ends_with($host, '.googleapis.com');
}

echo "\nUpload-Adresse\n";

check('Echte Upload-Adresse wird angenommen', istGoogleUploadUrl(
    'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=ABC'
));
check('Adresse ohne Verschlüsselung wird abgelehnt', ! istGoogleUploadUrl(
    'http://www.googleapis.com/upload/drive/v3/files'
));
check('Fremder Rechner wird abgelehnt', ! istGoogleUploadUrl(
    'https://evil.example.com/upload'
));
check('Angehängter Name wird abgelehnt', ! istGoogleUploadUrl(
    'https://googleapis.com.evil.example.com/upload'
), 'der klassische Trick mit dem passenden Anfang');
check('Rechnername im Benutzerteil wird abgelehnt', ! istGoogleUploadUrl(
    'https://www.googleapis.com@evil.example.com/upload'
));
check('Untergeordneter Rechner wird angenommen', istGoogleUploadUrl(
    'https://upload.googleapis.com/x'
));

// --- Dateiname im Header ------------------------------------------------------

// Nachbau von DbToolsPage::headerFileName. Namen aus Google Drive kann setzen, wer
// Zugriff auf den Ordner hat.
function headerDateiname(string $name): string
{
    $sauber = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name) ?? '';
    $sauber = trim($sauber);

    return $sauber === '' ? 'backup.zip' : $sauber;
}

echo "\nDateiname im Download-Header\n";

check('Normaler Name bleibt unverändert', headerDateiname('kunde-de-2026-07-28.zip') === 'kunde-de-2026-07-28.zip');
check(
    'Anführungszeichen fliegt raus',
    ! str_contains(headerDateiname('a".zip'), '"'),
    headerDateiname('a".zip')
);
check(
    'Zeilenumbruch fliegt raus',
    ! str_contains(headerDateiname("a\r\nX-Evil: 1.zip"), "\n"),
    'sonst liesse sich ein zweiter Header anhängen'
);
check('Nur Sonderzeichen ergibt einen brauchbaren Namen', headerDateiname('"""') === 'backup.zip');
check('Umlaute bleiben erhalten', headerDateiname('sicherung-für-müller.zip') === 'sicherung-für-müller.zip');

// --- Abschnittsgrösse ---------------------------------------------------------

const CHUNK_SIZE = 8 * 1024 * 1024;
const MIN_CHUNK_SIZE = 256 * 1024;

// Nachbau von UploadJob::chunkFromServerLimits.
function abschnittFuer(int $limitBytes): int
{
    if ($limitBytes <= 0) {
        return CHUNK_SIZE;
    }

    $abschnitt = intdiv($limitBytes, 12);
    $gerundet = intdiv($abschnitt, MIN_CHUNK_SIZE) * MIN_CHUNK_SIZE;

    return max(MIN_CHUNK_SIZE, min(CHUNK_SIZE, $gerundet));
}

echo "\nAbschnittsgrösse nach Speicherlimit\n";

check('Ohne Limit die grosszügige Grösse', abschnittFuer(0) === CHUNK_SIZE);
check('Bei 512 MB die grosszügige Grösse', abschnittFuer(512 * 1024 * 1024) === CHUNK_SIZE);
// Gemessen kostet ein Abschnitt rund das Dreifache seiner Grösse an Speicher. Bei
// vierundsechzig Megabyte Limit müssen daneben noch WordPress und die Anfrage selbst
// hineinpassen, ein Abschnitt von acht Megabyte wäre also zu gross.
check(
    'Bei 64 MB kleiner als das grosszügige Mass',
    abschnittFuer(64 * 1024 * 1024) < CHUNK_SIZE,
    (string) abschnittFuer(64 * 1024 * 1024)
);
check(
    'Bei 64 MB bleibt die Spitzenlast unter einem Drittel des Limits',
    abschnittFuer(64 * 1024 * 1024) * 3 < 64 * 1024 * 1024 / 3 + 1024 * 1024,
    (string) (abschnittFuer(64 * 1024 * 1024) * 3)
);
check(
    'Bei 32 MB noch kleiner',
    abschnittFuer(32 * 1024 * 1024) < abschnittFuer(64 * 1024 * 1024),
    (string) abschnittFuer(32 * 1024 * 1024)
);
check(
    'Nie unter das Mindestmass',
    abschnittFuer(1024 * 1024) >= MIN_CHUNK_SIZE,
    (string) abschnittFuer(1024 * 1024)
);
check(
    'Immer ein Vielfaches des Mindestmasses',
    abschnittFuer(100 * 1024 * 1024) % MIN_CHUNK_SIZE === 0
);

echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}

echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
