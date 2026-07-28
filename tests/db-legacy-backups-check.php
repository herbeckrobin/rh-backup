<?php

// Ein Bestandskunde hat vor dem Update Archive gesammelt. Was macht das Update damit?

$engine = rh_db_engine();
$storage = $engine->storage();

$flach = $storage->backupsPath();
$alt = $storage->autoBackupsPath();

// Zustand vor dem Update nachstellen: flach abgelegte Archive, keine Unterordner.
$angelegt = [];
for ($i = 1; $i <= 12; $i++) {
    $pfad = $flach . '/backup-2026010' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '-120000-bestand' . $i . '.zip';
    file_put_contents($pfad, str_repeat('a', 512));
    touch($pfad, time() - (13 - $i) * 86400);
    $angelegt[] = basename($pfad);
}
for ($i = 1; $i <= 6; $i++) {
    $pfad = $alt . '/auto-2026010' . $i . '-120000-kopie' . $i . '.zip';
    file_put_contents($pfad, str_repeat('b', 512));
    touch($pfad, time() - (7 - $i) * 86400);
}

printf("Vor dem Wartungslauf: %d flach, %d Sicherungskopien%s",
    count($storage->listBackupsIn($flach)),
    count($storage->listBackupsIn($alt)),
    PHP_EOL
);

// Und jetzt genau das, was nach dem Update als Erstes passiert.
(new \RhBackup\Maintenance($storage))->run();

$nachFlach = $storage->listBackupsIn($flach);
$nachAlt = $storage->listBackupsIn($alt);

printf("Nach dem Wartungslauf: %d flach, %d Sicherungskopien%s", count($nachFlach), count($nachAlt), PHP_EOL);
printf("%s%s",
    count($nachFlach) === 12 && count($nachAlt) === 6
        ? 'RICHTIG: der Altbestand ist unangetastet.'
        : 'FALSCH: das Update hat Archive entfernt.',
    PHP_EOL
);

// Gegenprobe: lassen sich die alten Archive weiterhin finden und öffnen?
$conn = new \RhBackup\Offsite\Connection();
$drive = new \RhBackup\Offsite\GoogleDrive($conn);
$stores = new \RhBackup\Storage\StoreRegistry($storage, $drive, $conn);
rhbp_update_setting('offsite', 'mode', 'local');

$liste = $stores->get('local')->list();
$gefunden = 0;
foreach ($liste as $e) {
    if (in_array($e->name, $angelegt, true)) {
        $gefunden++;
    }
}
printf("%sIn der Übersicht sichtbar: %d von %d%s", PHP_EOL, $gefunden, count($angelegt), PHP_EOL);

$erste = null;
foreach ($liste as $e) {
    if ($e->name === $angelegt[0]) {
        $erste = $e;
    }
}
if ($erste !== null) {
    printf("Anlass des Alt-Archivs: %s%s", \RhBackup\Storage\BackupKind::label($erste->kind), PHP_EOL);
    $strom = $stores->get('local')->open($erste->ref);
    printf("Lesbar: %s (%d Bytes)%s", $strom->size() === 512 ? 'ja' : 'nein', $strom->size(), PHP_EOL);
    $strom->close();
} else {
    printf("FALSCH: das Alt-Archiv taucht in der Übersicht nicht auf.%s", PHP_EOL);
}

// Und wird der neue Bestand weiterhin rotiert?
$presync = $storage->backupsSubPath('presync');
for ($i = 1; $i <= 8; $i++) {
    $pfad = $presync . '/rh-presync-' . $i . '.zip';
    file_put_contents($pfad, str_repeat('c', 128));
    touch($pfad, time() - (9 - $i) * 3600);
}
$vorher = count($storage->listBackupsIn($presync));
(new \RhBackup\Maintenance($storage))->run();
$nachher = count($storage->listBackupsIn($presync));
printf("%sNeuer Bestand (presync): %d vor, %d nach der Rotation, %s%s",
    PHP_EOL, $vorher, $nachher,
    $nachher < $vorher ? 'wird rotiert' : 'FALSCH: wird nicht rotiert',
    PHP_EOL
);

// Aufräumen: nur was dieser Test angelegt hat.
foreach (glob($flach . '/backup-20260*-bestand*.zip') ?: [] as $p) {
    unlink($p);
}
foreach (glob($alt . '/auto-20260*-kopie*.zip') ?: [] as $p) {
    unlink($p);
}
foreach (glob($presync . '/rh-presync-*.zip') ?: [] as $p) {
    unlink($p);
}
printf("%sTestdateien entfernt.%s", PHP_EOL, PHP_EOL);
