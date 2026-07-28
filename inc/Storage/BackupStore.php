<?php

declare(strict_types=1);

namespace RhBackup\Storage;

use RhDbEngine\Archive\ArchiveStream;

/**
 * Ein Ort, an dem Sicherungen liegen.
 *
 * Es gibt immer genau einen davon, der gilt. Er entscheidet, wohin jede Sicherung geht,
 * was in der Liste steht und woher wiederhergestellt wird. Das ist der Grund für diese
 * Schnittstelle: ohne sie müsste jede Stelle, die mit Sicherungen umgeht, den Unterschied
 * zwischen Platte und Google Drive selbst kennen, und es sind viele Stellen.
 *
 * Keine Vorratshaltung: es gibt heute schon zwei, und beide werden gebraucht. Dass ein
 * dritter dadurch später eine Ergänzung statt eines Umbaus wäre, ist ein Nebeneffekt und
 * nicht der Zweck.
 */
interface BackupStore
{
    /**
     * Kennung für die Einstellung. Stabil, wird gespeichert.
     */
    public function id(): string;

    /**
     * Wie er in der Oberfläche heisst.
     */
    public function label(): string;

    /**
     * Ist er benutzbar? Google Drive braucht ein verbundenes Konto, die Platte nicht.
     */
    public function isReady(): bool;

    /**
     * Warum er nicht benutzbar ist, oder eine leere Zeichenkette.
     */
    public function notReadyReason(): string;

    /**
     * Alle Sicherungen, neueste zuerst.
     *
     * @return array<int, BackupEntry>
     */
    public function list(): array;

    public function find(string $ref): ?BackupEntry;

    /**
     * Entfernt eine Sicherung. Endgültig.
     */
    public function delete(string $ref): bool;

    /**
     * Öffnet eine Sicherung zum Lesen.
     *
     * Bewusst ein Strom und kein Pfad: was in Google Drive liegt, hat keinen Pfad, und
     * ein Archiv von hundert Megabyte am Stück in den Speicher zu holen ist keine Option.
     *
     * @throws \RuntimeException wenn es die Sicherung nicht gibt.
     */
    public function open(string $ref): ArchiveStream;

    /**
     * Nimmt eine Sicherung auf, gelesen aus einem Strom.
     *
     * Läuft über mehrere Aufrufe: $offset sagt, wo weitergemacht wird, der Rückgabewert
     * wie weit gekommen wurde. Ein Archiv von mehreren Gigabyte passt in keinen Request,
     * und ein Abbruch darf nicht bedeuten, dass wieder bei null begonnen wird.
     *
     * @param array<string, mixed> $state Zustand zwischen zwei Aufrufen, vom Aufrufer gespeichert.
     * @return array{offset: int, done: bool, state: array<string, mixed>}
     */
    public function receive(string $name, ArchiveStream $source, int $offset, array $state, float $budget): array;

    /**
     * Räumt einen abgebrochenen Empfang auf.
     *
     * @param array<string, mixed> $state
     */
    public function abortReceive(string $name, array $state): void;
}
