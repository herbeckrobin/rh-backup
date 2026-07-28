<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhBackup\Offsite\GoogleDrive;
use RhDbEngine\Archive\ArchiveStream;

/**
 * Liest eine Sicherung, die in Google Drive liegt.
 *
 * Damit lässt sich ein Archiv aus Drive genauso behandeln wie eines auf der Platte:
 * Grösse abfragen, an einer Stelle lesen. Der Unterschied ist, dass jedes Lesen über das
 * Netz geht, weshalb hier ein Puffer sitzt. Ohne ihn würde ein Aufrufer, der in kleinen
 * Häppchen liest, für jedes Häppchen eine eigene Anfrage auslösen.
 */
final class DriveArchiveStream implements ArchiveStream
{
    /** So viel wird auf einmal geholt, auch wenn weniger gefragt war. */
    private const WINDOW = 8 * 1024 * 1024;

    private ?int $size = null;
    private string $buffer = '';
    private int $bufferStart = 0;

    public function __construct(
        private readonly GoogleDrive $drive,
        private readonly string $fileId,
        private readonly int $knownSize = 0,
    ) {
    }

    public function size(): int
    {
        if ($this->size === null) {
            $this->size = $this->knownSize > 0 ? $this->knownSize : $this->drive->fileSize($this->fileId);

            if ($this->size < 0) {
                throw new \RuntimeException(__('Die Sicherung ist in Google Drive nicht mehr auffindbar.', 'rh-backup'));
            }
        }

        return $this->size;
    }

    public function readAt(int $offset, int $length): string
    {
        $gesamt = $this->size();

        if ($length <= 0 || $offset < 0 || $offset >= $gesamt) {
            return '';
        }

        $length = min($length, $gesamt - $offset);

        // Liegt das Gewünschte schon im Puffer, kostet es keine Anfrage.
        if ($this->covers($offset, $length)) {
            return substr($this->buffer, $offset - $this->bufferStart, $length);
        }

        $holen = max($length, self::WINDOW);
        $holen = min($holen, $gesamt - $offset);

        $this->buffer = $this->drive->readRange($this->fileId, $offset, $holen);
        $this->bufferStart = $offset;

        return substr($this->buffer, 0, $length);
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->bufferStart = 0;
    }

    private function covers(int $offset, int $length): bool
    {
        if ($this->buffer === '') {
            return false;
        }

        return $offset >= $this->bufferStart
            && ($offset + $length) <= ($this->bufferStart + strlen($this->buffer));
    }
}
