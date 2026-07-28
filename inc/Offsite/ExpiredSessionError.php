<?php

declare(strict_types=1);

namespace RhBackup\Offsite;

/**
 * Die Upload-Sitzung bei Google gilt nicht mehr (404 auf die Sitzungs-Adresse).
 *
 * Google hält eine fortsetzbare Sitzung eine Woche vor. Läuft sie ab, ist der bisherige
 * Fortschritt verloren, aber das lokale Backup liegt noch da: der Lauf beginnt mit einer
 * neuen Sitzung von vorn, statt abzubrechen.
 */
final class ExpiredSessionError extends TransientUploadError
{
}
