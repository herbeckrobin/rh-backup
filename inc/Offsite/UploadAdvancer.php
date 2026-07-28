<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

use RhBackup\Storage\FullSiteArchive;
use RhDbEngine\Archive\ArchiveStream;
use RhDbEngine\Archive\FileArchiveStream;
use RhDbEngine\ExportCursor;
use RhDbEngine\Exporter;
use RhDbEngine\Storage;

/**
 * Bringt einen Offsite-Lauf um einen Schritt voran.
 *
 * Phasen: export, session, upload, verify, rotate, done.
 *
 * Jeder Aufruf arbeitet nur so lange, wie das Zeitbudget des Ticks erlaubt, und
 * hinterlässt einen Zustand, aus dem der nächste Aufruf nahtlos weitermacht. Das gilt
 * für beide langen Teile: den Export (über den Cursor der db-engine) und den Upload
 * (über den Byte-Offset der Google-Sitzung).
 *
 * Die Reihenfolge verify vor rotate ist nicht verhandelbar: es wird niemals eine alte
 * Kopie gelöscht, bevor die neue nachweislich vollständig bei Google liegt.
 */
final class UploadAdvancer
{
    public function __construct(
        private readonly Storage $storage,
        private readonly Exporter $exporter,
        private readonly GoogleDrive $drive,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @throws \RuntimeException bei endgültigen Fehlern.
     */
    public function advance(UploadJob $job): void
    {
        match ($job->phase) {
            UploadJob::PHASE_EXPORT => $job->isFullSite() ? $this->stepFullSite($job) : $this->stepExport($job),
            UploadJob::PHASE_WRITE => $this->stepWrite($job),
            UploadJob::PHASE_SESSION => $this->stepSession($job),
            UploadJob::PHASE_UPLOAD => $this->stepUpload($job),
            UploadJob::PHASE_VERIFY => $this->stepVerify($job),
            UploadJob::PHASE_ROTATE => $this->stepRotate($job),
            default => $job->finishFailure(sprintf('Unbekannte Phase: %s', $job->phase)),
        };
    }

    // ============================================================
    // 1. Backup erzeugen
    // ============================================================

    private function stepExport(UploadJob $job): void
    {
        $cursor = $job->exportCursor === []
            ? ExportCursor::start(
                $this->storage->jobWorkdir('offsite-' . $job->jobId),
                Settings::includeUploads(),
                [],
                // Das Archiv liegt im Arbeitsverzeichnis des Laufs, nicht in der
                // Backup-Liste: es ist Transportgut und wird nach dem Upload gelöscht.
                $this->storage->jobWorkdir('offsite-' . $job->jobId)
            )
            : ExportCursor::fromArray($job->exportCursor);

        $cursor = $this->exporter->exportStep($cursor, $job->tickBudget);
        $job->exportCursor = $cursor->toArray();

        if (! $cursor->isDone()) {
            $job->message = __('Backup wird erstellt...', 'rh-backup');
            $job->save();

            return;
        }

        $zipPath = (string) $cursor->zipPath;
        if ($zipPath === '' || ! is_file($zipPath)) {
            throw new \RuntimeException(__('Das Backup wurde nicht erzeugt.', 'rh-backup'));
        }

        $size = (int) filesize($zipPath);
        if ($size <= 0) {
            throw new \RuntimeException(__('Das erzeugte Backup ist leer.', 'rh-backup'));
        }

        $job->zipPath = $zipPath;
        $job->totalSize = $size;
        $job->fileName = $this->fileName($job);

        // Im lokalen Modus ist der Lauf hier zu Ende: das Archiv wandert aus dem
        // Arbeitsverzeichnis zu den Backups und bleibt dort. Ohne Google, ohne Upload.
        if ($job->isLocalMode()) {
            $job->zipPath = $this->moveToBackups($zipPath, $job->kind(), $job->fileName);
            $this->cleanupWorkdir($job);
            $job->finishSuccess(sprintf(
                /* translators: %s: file size */
                __('Backup erstellt (%s).', 'rh-backup'),
                size_format($size)
            ));

            return;
        }

        $job->phase = UploadJob::PHASE_SESSION;
        $job->message = __('Backup erstellt, Übertragung wird vorbereitet.', 'rh-backup');
        $job->save();
    }

    /**
     * Bereitet ein Archiv der kompletten Website vor.
     *
     * Anders als der herkömmliche Weg entsteht hier keine Archivdatei. Vorbereitet werden
     * nur die Dateiliste, die Prüfsummen und der Datenbank-Dump, zusammen ein paar Prozent
     * dessen, was das fertige Archiv gross wäre. Das Archiv selbst entsteht erst beim
     * Lesen, also während der Übertragung.
     */
    private function stepFullSite(UploadJob $job): void
    {
        $archiv = FullSiteArchive::resume(
            $this->storage,
            $this->exporter,
            $this->storage->jobWorkdir('offsite-' . $job->jobId),
            $job->archiveState
        );

        $archiv->advance($job->tickBudget);
        $job->archiveState = $archiv->state();

        if (! $archiv->isReady()) {
            $job->message = $archiv->detail();
            $job->save();

            return;
        }

        $job->totalSize = $archiv->size();
        $job->fileName = $this->fileName($job);

        if ($job->totalSize <= 0) {
            throw new \RuntimeException(__('Das erzeugte Backup ist leer.', 'rh-backup'));
        }

        // Lokal muss es doch auf die Platte, dafür gibt es einen eigenen Schritt: bei
        // mehreren Gigabyte passt das Schreiben in keinen einzelnen Aufruf.
        if ($job->isLocalMode()) {
            $job->zipPath = trailingslashit($this->storage->backupsSubPath($job->kind())) . $job->fileName;
            $job->offset = 0;
            $job->phase = UploadJob::PHASE_WRITE;
            $job->message = __('Backup wird geschrieben...', 'rh-backup');
            $job->save();

            return;
        }

        $job->phase = UploadJob::PHASE_SESSION;
        $job->message = __('Backup vorbereitet, Übertragung wird vorbereitet.', 'rh-backup');
        $job->save();
    }

    /**
     * Schreibt ein streamendes Archiv abschnittsweise auf die Platte.
     *
     * Nur für den lokalen Modus. Der Fortschritt läuft über denselben Byte-Offset wie
     * eine Übertragung, dadurch ist auch dieser Schritt jederzeit fortsetzbar.
     */
    private function stepWrite(UploadJob $job): void
    {
        $stream = $this->openStream($job);
        $deadline = microtime(true) + $job->tickBudget;

        $handle = fopen($job->zipPath, $job->offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new \RuntimeException(__('Das Backup konnte nicht geschrieben werden.', 'rh-backup'));
        }

        try {
            while ($job->offset < $job->totalSize && microtime(true) < $deadline) {
                $daten = $stream->readAt($job->offset, $job->chunkSize);
                if ($daten === '') {
                    throw new \RuntimeException(__('Das Backup liefert keine Daten mehr, obwohl es noch nicht vollständig ist.', 'rh-backup'));
                }

                if (fwrite($handle, $daten) !== strlen($daten)) {
                    throw new \RuntimeException(__('Das Backup konnte nicht vollständig geschrieben werden. Bitte den Plattenplatz prüfen.', 'rh-backup'));
                }

                $job->offset += strlen($daten);
                $job->message = sprintf(
                    /* translators: %1$s: written, %2$s: total */
                    __('Backup wird geschrieben, %1$s von %2$s.', 'rh-backup'),
                    size_format($job->offset),
                    size_format($job->totalSize)
                );
                $job->save();
            }
        } finally {
            fclose($handle);
            $stream->close();
        }

        if ($job->offset < $job->totalSize) {
            return;
        }

        $this->storage->protectFile($job->zipPath);
        $this->cleanupWorkdir($job);

        $job->finishSuccess(sprintf(
            /* translators: %s: file size */
            __('Backup erstellt (%s).', 'rh-backup'),
            size_format($job->totalSize)
        ));
    }

    /**
     * Verschiebt das fertige Archiv aus dem Arbeitsverzeichnis in den Ordner seines Anlasses.
     *
     * Verschieben statt kopieren: bei einem Archiv von mehreren Gigabyte wären beide
     * Kopien gleichzeitig auf der Platte, und genau daran scheitert es auf knappem
     * Hosting. Klappt das Umbenennen nicht (getrennte Dateisysteme), bleibt nur der
     * Weg über eine Kopie.
     */
    private function moveToBackups(string $zipPath, string $kind, string $name): string
    {
        $ziel = trailingslashit($this->storage->backupsSubPath($kind)) . $name;

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- der Fehlschlag wird unten aufgefangen.
        if (@rename($zipPath, $ziel)) {
            $this->storage->protectFile($ziel);

            return $ziel;
        }

        if (! copy($zipPath, $ziel)) {
            throw new \RuntimeException(__('Das Backup konnte nicht in den Backup-Ordner verschoben werden.', 'rh-backup'));
        }

        $this->storage->protectFile($ziel);
        wp_delete_file($zipPath);

        return $ziel;
    }

    /**
     * Sprechender Dateiname in Drive: Domain und Zeitpunkt.
     *
     * Die Punkte der Domain werden vorher zu Bindestrichen. sanitize_file_name() würde
     * sonst einen Unterstrich einschieben, um eine doppelte Dateiendung zu verhindern,
     * und aus "kunde.de" würde "kunde_.de".
     *
     * Der Zeitstempel geht bis auf die Sekunde: zwei Läufe in derselben Minute ergäben
     * sonst zwei gleichnamige Dateien im selben Drive-Ordner.
     */
    private function fileName(UploadJob $job): string
    {
        $host = str_replace('.', '-', Settings::siteFolderName());

        // Anlass und Umfang gehören in den Namen, nicht nur in einen Ordner. In Google
        // Drive liegt alles flach nebeneinander, und ohne diese Angabe wäre später nicht
        // mehr zu erkennen, ob eine Sicherung planmässig entstand oder von Hand, und ob
        // die ganze Website darin steckt oder nur die Datenbank.
        // Der Zufallsteil bleibt: liegt der Backup-Ordner auf einem Server, dessen
        // Konfiguration ihn nicht sperrt, ist ein nicht erratbarer Name die eigentliche
        // Absicherung.
        return sprintf(
            '%s-%s-%s-%s-%s.zip',
            sanitize_file_name($host),
            $job->kind(),
            $job->scope,
            gmdate('Y-m-d-His'),
            wp_generate_password(10, false, false)
        );
    }

    // ============================================================
    // 2. Upload-Sitzung eröffnen
    // ============================================================

    private function stepSession(UploadJob $job): void
    {
        $folderId = $this->drive->ensureFolder();

        $job->sessionUri = $this->drive->startUploadSession($job->fileName, $job->totalSize, $folderId);
        $job->offset = 0;
        $job->phase = UploadJob::PHASE_UPLOAD;
        $job->message = __('Übertragung nach Google Drive läuft...', 'rh-backup');
        $job->save();
    }

    // ============================================================
    // 3. Abschnittsweise übertragen
    // ============================================================

    private function stepUpload(UploadJob $job): void
    {
        $stream = $this->openStream($job);
        $deadline = microtime(true) + $job->tickBudget;

        try {
            while ($job->offset < $job->totalSize && microtime(true) < $deadline) {
                $chunk = $stream->readAt($job->offset, $job->chunkSize);
                if ($chunk === '') {
                    throw new \RuntimeException(__('Das lokale Backup liefert keine Daten mehr, obwohl es noch nicht vollständig übertragen ist.', 'rh-backup'));
                }

                try {
                    $result = $this->drive->uploadChunk($job->sessionUri, $chunk, $job->offset, $job->totalSize);
                } catch (ExpiredSessionError) {
                    // Sitzung abgelaufen: neue eröffnen und von vorn beginnen.
                    $job->sessionUri = '';
                    $job->offset = 0;
                    $job->phase = UploadJob::PHASE_SESSION;
                    $job->message = __('Die Übertragung wird neu begonnen, die Sitzung bei Google war abgelaufen.', 'rh-backup');
                    $job->save();

                    return;
                } catch (TransientUploadError $e) {
                    $this->handleTransientChunkError($job, $e);

                    return;
                }

                // Google nennt selbst die Position, an der weitergemacht wird. Das ist
                // verlässlicher als eigenes Mitzählen.
                $job->offset = $result['done'] ? $job->totalSize : max($job->offset + strlen($chunk), $result['next_offset']);
                $job->clearRetries();

                if ($result['done']) {
                    $job->fileId = $result['file_id'];
                    $job->phase = UploadJob::PHASE_VERIFY;
                    $job->message = __('Übertragung abgeschlossen, wird geprüft.', 'rh-backup');
                    $job->save();

                    return;
                }

                $job->message = sprintf(
                    /* translators: %1$s: transferred size, %2$s: total size */
                    __('Übertragung läuft, %1$s von %2$s.', 'rh-backup'),
                    size_format($job->offset),
                    size_format($job->totalSize)
                );
                $job->save();
            }
        } finally {
            $stream->close();
        }

        // Budget aufgebraucht, der nächste Durchlauf macht am selben Offset weiter.
        $job->save();
    }

    /**
     * Woraus dieser Lauf liest.
     *
     * Heute immer aus dem fertigen Archiv auf der Platte. Die Schnittstelle liegt trotzdem
     * schon davor, damit später ein Archiv daneben treten kann, das beim Lesen erst
     * entsteht und deshalb nie ganz auf der Platte liegt.
     */
    private function openStream(UploadJob $job): ArchiveStream
    {
        /**
         * Erlaubt es, eine andere Quelle unterzuschieben.
         *
         * @param ArchiveStream|null $stream
         * @param UploadJob          $job
         */
        $eigen = apply_filters('rh-backup/offsite/archive_stream', null, $job);
        if ($eigen instanceof ArchiveStream) {
            return $eigen;
        }

        // Beim Voll-Backup gibt es keine Archivdatei, aus der man lesen könnte. Sie
        // entsteht genau in diesem Moment, aus Dateiliste und Prüfsummen.
        if ($job->isFullSite()) {
            return FullSiteArchive::resume(
                $this->storage,
                $this->exporter,
                $this->storage->jobWorkdir('offsite-' . $job->jobId),
                $job->archiveState
            )->stream();
        }

        if (! is_file($job->zipPath)) {
            throw new \RuntimeException(__('Das lokale Backup ist verschwunden, der Lauf kann nicht fortgesetzt werden.', 'rh-backup'));
        }

        return new FileArchiveStream($job->zipPath);
    }

    /**
     * Reagiert auf einen Abschnitt, der nicht durchkam.
     *
     * Erst die Abschnittsgrösse verkleinern (manche Server und Firewalls brechen bei
     * grossen Rümpfen ab), dann mit wachsendem Abstand erneut versuchen. Beim nächsten
     * Anlauf wird zuerst bei Google nachgefragt, wie viel wirklich angekommen ist.
     */
    private function handleTransientChunkError(UploadJob $job, TransientUploadError $error): void
    {
        $reduced = $job->reduceChunkSize();

        if (! $job->scheduleRetry($error->retryAfter())) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: error message */
                __('Die Übertragung ist mehrfach gescheitert und wurde abgebrochen. Letzter Fehler: %s', 'rh-backup'),
                $error->getMessage()
            ));
        }

        $job->message = $reduced
            ? sprintf(
                /* translators: %s: chunk size */
                __('Übertragung stockt, es wird mit kleineren Abschnitten (%s) weiterversucht.', 'rh-backup'),
                size_format($job->chunkSize)
            )
            : __('Übertragung stockt, der nächste Versuch folgt gleich.', 'rh-backup');

        // Offset an der Wahrheit von Google ausrichten, statt ihn zu raten.
        try {
            $status = $this->drive->queryUploadStatus($job->sessionUri, $job->totalSize);
            if ($status['done']) {
                $job->offset = $job->totalSize;
                $job->fileId = $status['file_id'];
                $job->phase = UploadJob::PHASE_VERIFY;
            } else {
                $job->offset = $status['next_offset'];
            }
        } catch (\RuntimeException) {
            // Auch die Nachfrage kann scheitern. Dann bleibt der bisherige Offset stehen,
            // der nächste Versuch fragt erneut.
        }

        $job->save();
    }

    // ============================================================
    // 4. Prüfen
    // ============================================================

    private function stepVerify(UploadJob $job): void
    {
        if ($job->fileId === '') {
            throw new \RuntimeException(__('Google Drive hat keine Datei-Kennung geliefert, das Backup gilt als nicht gesichert.', 'rh-backup'));
        }

        $remoteSize = $this->drive->fileSize($job->fileId);

        if ($remoteSize !== $job->totalSize) {
            throw new \RuntimeException(sprintf(
                /* translators: %1$s: remote size, %2$s: local size */
                __('Die Datei in Google Drive ist unvollständig (%1$s statt %2$s). Es wird nichts gelöscht.', 'rh-backup'),
                $remoteSize < 0 ? __('nicht lesbar', 'rh-backup') : size_format($remoteSize),
                size_format($job->totalSize)
            ));
        }

        $job->phase = UploadJob::PHASE_ROTATE;
        $job->message = __('Backup vollständig übertragen, alte Kopien werden aufgeräumt.', 'rh-backup');
        $job->save();
    }

    // ============================================================
    // 5. Alte Kopien aufräumen
    // ============================================================

    private function stepRotate(UploadJob $job): void
    {
        $keep = Settings::keepCopies();

        try {
            $folderId = $this->drive->ensureFolder();
            $files = $this->drive->listBackups($folderId);

            // Sicherheitsnetz: die gerade hochgeladene Datei wird nie angefasst.
            $obsolete = array_slice(
                array_values(array_filter($files, static fn (array $f): bool => $f['id'] !== $job->fileId)),
                max(0, $keep - 1)
            );

            foreach ($obsolete as $file) {
                if ($this->drive->deleteFile($file['id'])) {
                    $job->deletedCopies++;
                }
            }
        } catch (\RuntimeException $e) {
            // Das Backup liegt vollständig in Drive, nur das Aufräumen ging schief.
            // Das ist kein Fehlschlag des Laufs, aber es gehört ins Protokoll.
            $this->log(sprintf('Rotation fehlgeschlagen: %s', $e->getMessage()));
        }

        $this->cleanupLocal($job);

        // Die Liste in Drive hat sich gerade geändert. Ohne diesen Schritt zeigt die
        // Oberfläche bis zu zwei Minuten den Stand von vorher, und wer direkt nach dem
        // Sichern nachsieht, findet seine frische Sicherung nicht.
        \RhBackup\Storage\DriveStore::forgetList();

        $job->finishSuccess(sprintf(
            /* translators: %1$s: file name, %2$s: size */
            __('Backup "%1$s" (%2$s) liegt in Google Drive.', 'rh-backup'),
            $job->fileName,
            size_format($job->totalSize)
        ));
    }

    /**
     * Entfernt das lokale Transport-Archiv samt Arbeitsverzeichnis.
     */
    private function cleanupLocal(UploadJob $job): void
    {
        if ($job->zipPath !== '' && is_file($job->zipPath)) {
            wp_delete_file($job->zipPath);
        }

        $this->cleanupWorkdir($job);
    }

    /**
     * Räumt das Arbeitsverzeichnis eines Laufs, ohne das Ergebnis anzufassen.
     *
     * Was dort liegen bleibt, ist nicht harmlos: die db.sql enthält die komplette
     * Datenbank im Klartext, samt Passwort-Hashes. Bis die Aufräum-Routine sie nach zwei
     * Stunden abholt, liegt sie lesbar auf der Platte. Darum sofort nach dem Lauf.
     */
    private function cleanupWorkdir(UploadJob $job): void
    {
        $workdir = $this->storage->jobsPath() . '/offsite-' . $job->jobId;
        if (! is_dir($workdir)) {
            return;
        }

        foreach (glob(trailingslashit($workdir) . '*') ?: [] as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Aufräumen, ein Fehlschlag ist unkritisch.
        @rmdir($workdir);
    }

    private function log(string $message): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnose auf Kundenseiten, sonst nicht nachvollziehbar.
        error_log('[rh-backup] Offsite: ' . $message);
    }
}
