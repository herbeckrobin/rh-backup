<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Ein Fehler, der beim nächsten Versuch verschwunden sein kann.
 *
 * Netzwerkabbruch, Zeitüberschreitung, Überlastung bei Google, Rate-Limit. Der Lauf
 * wird deshalb nicht beendet, sondern wartet und macht weiter. Abgegrenzt von einem
 * gewöhnlichen RuntimeException, das einen endgültigen Fehlschlag bedeutet.
 */
class TransientUploadError extends \RuntimeException
{
    public function __construct(string $message, private readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }

    /**
     * Von Google vorgegebene Wartezeit in Sekunden, 0 wenn keine genannt wurde.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
