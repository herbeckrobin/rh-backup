<?php

declare(strict_types=1);

namespace RhBackup\Storage;

/**
 * Eine Sicherung, unabhängig davon wo sie liegt.
 *
 * Die Kennung ist das, womit der jeweilige Ablageort sie wiederfindet: lokal ein Pfad
 * relativ zum Backup-Ordner, in Google Drive die Datei-Kennung. Wer damit arbeitet,
 * muss den Unterschied nicht kennen.
 */
final class BackupEntry
{
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly int $size,
        public readonly int $time,
        public readonly string $kind = BackupKind::LEGACY,
    ) {
    }

    public function kindLabel(): string
    {
        return BackupKind::label($this->kind);
    }

    /**
     * Ein Voll-Backup lässt sich nicht über das Plugin zurückspielen.
     *
     * Erkannt am Namen, weil das die einzige Angabe ist, die überall vorliegt: in Drive
     * gibt es keine Ordner-Struktur, aus der man es ableiten könnte, und die Datei erst
     * herunterzuladen, um im Manifest nachzusehen, wäre für eine Liste absurd.
     */
    public function isFullSite(): bool
    {
        return str_contains($this->name, '-full-');
    }
}
