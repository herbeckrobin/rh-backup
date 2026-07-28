<?php

declare(strict_types=1);

namespace RhBackup\Storage;

/**
 * Aus welchem Anlass eine Sicherung entstanden ist.
 *
 * Bisher landete alles im selben flachen Ordner: der planmässige Lauf, die Kopie, die
 * rh-sync vor einem Import anlegt, und was jemand von Hand angestossen hat. In der Liste
 * war danach nicht mehr zu erkennen, was wovon stammt, und die Rotation behandelte alles
 * gleich, obwohl die Zwecke verschieden sind: von den planmässigen will man viele Stände,
 * von den Sicherungskopien nur die letzten paar.
 *
 * Die Anlässe stehen deshalb an genau einer Stelle, samt Ordnername und Vorgabe für die
 * Aufbewahrung. Wer einen weiteren braucht, ergänzt ihn hier und sonst nirgends.
 */
final class BackupKind
{
    /** Zeitgesteuerter Lauf. */
    public const AUTOMATIC = 'automatic';

    /** Sicherungskopie, die vor einem Import angelegt wird. Auch die von rh-sync. */
    public const PRESYNC = 'presync';

    /** Von Hand angestossen. */
    public const MANUAL = 'manual';

    /** Archive aus der Zeit vor den Unterordnern, flach im Backup-Ordner. */
    public const LEGACY = '';

    /**
     * @return array<string, array{label: string, keep: int}>
     */
    public static function all(): array
    {
        return [
            self::AUTOMATIC => [
                'label' => __('Planmässig', 'rh-backup'),
                'keep' => 10,
            ],
            self::PRESYNC => [
                'label' => __('Vor einem Sync', 'rh-backup'),
                'keep' => 3,
            ],
            self::MANUAL => [
                'label' => __('Von Hand', 'rh-backup'),
                'keep' => 5,
            ],
        ];
    }

    public static function isValid(string $kind): bool
    {
        return isset(self::all()[$kind]);
    }

    public static function label(string $kind): string
    {
        if ($kind === self::LEGACY) {
            return __('Ohne Zuordnung', 'rh-backup');
        }

        return (string) (self::all()[$kind]['label'] ?? $kind);
    }

    /**
     * Voreingestellte Anzahl aufbewahrter Stände.
     */
    public static function defaultKeep(string $kind): int
    {
        return (int) (self::all()[$kind]['keep'] ?? 5);
    }

    /**
     * Zu welchem Anlass gehört ein Archiv, gemessen an seinem relativen Pfad?
     */
    public static function fromPath(string $relativePath): string
    {
        $teile = explode('/', str_replace('\\', '/', $relativePath));
        if (count($teile) < 2) {
            return self::LEGACY;
        }

        return self::isValid($teile[0]) ? $teile[0] : self::LEGACY;
    }
}
