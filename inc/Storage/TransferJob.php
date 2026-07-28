<?php

declare(strict_types=1);

namespace RhBackup\Storage;

/**
 * Zustand eines laufenden Umzugs zwischen zwei Ablageorten.
 *
 * Wie beim Sichern eine Option mit autoload=no und kein Transient: ein Umzug von mehreren
 * Archiven kann über Stunden gehen, und ein Objekt-Cache-Flush darf den Fortschritt nicht
 * vernichten.
 *
 * Die Warteschlange steht als Liste im Zustand, weil ein Umzug fast nie aus einem Archiv
 * besteht. Abgearbeitet wird von vorn, fertige fallen heraus, dadurch ist die Liste
 * zugleich der Fortschritt.
 */
final class TransferJob
{
    public const OPTION = 'rhbackup_transfer_job';

    /** Wozu der Umzug dient. */
    public const PURPOSE_MOVE = 'move';
    public const PURPOSE_RESTORE = 'restore';

    public const PHASE_RUNNING = 'running';
    public const PHASE_DONE = 'done';
    public const PHASE_FAILED = 'failed';

    /** Ohne Lebenszeichen gilt ein Umzug als hängend. */
    public const STALE_AFTER = 180;

    /**
     * @param array<int, array{ref: string, name: string, size: int}> $queue   Was noch zu tun ist.
     * @param array<string, mixed>                                    $state   Zustand des laufenden Empfangs.
     */
    private function __construct(
        public string $jobId,
        public string $spawnToken,
        public string $phase,
        public string $fromStore,
        public string $toStore,
        public bool $deleteAfter,
        public string $purpose,
        public string $login,
        public string $restoreName,
        public array $queue,
        public int $offset,
        public array $state,
        public int $doneCount,
        public int $doneBytes,
        public int $totalCount,
        public int $totalBytes,
        public int $createdAt,
        public int $lastUpdateAt,
        public string $message,
        public string $error,
        public float $tickBudget,
    ) {
    }

    /**
     * @param array<int, BackupEntry> $entries
     */
    public static function create(
        string $from,
        string $to,
        array $entries,
        bool $deleteAfter,
        string $purpose = self::PURPOSE_MOVE,
        string $login = ''
    ): self {
        $queue = array_map(
            static fn (BackupEntry $e): array => ['ref' => $e->ref, 'name' => $e->name, 'size' => $e->size],
            array_values($entries)
        );

        $job = new self(
            jobId: bin2hex(random_bytes(16)),
            spawnToken: bin2hex(random_bytes(16)),
            phase: self::PHASE_RUNNING,
            fromStore: $from,
            toStore: $to,
            deleteAfter: $deleteAfter,
            purpose: $purpose,
            login: $login,
            restoreName: '',
            queue: $queue,
            offset: 0,
            state: [],
            doneCount: 0,
            doneBytes: 0,
            totalCount: count($queue),
            totalBytes: array_sum(array_column($queue, 'size')),
            createdAt: time(),
            lastUpdateAt: time(),
            message: __('Umzug wird vorbereitet...', 'rh-backup'),
            error: '',
            tickBudget: 15.0,
        );
        $job->save();

        return $job;
    }

    public static function load(): ?self
    {
        $data = get_option(self::OPTION);
        if (! is_array($data) || empty($data['job_id'])) {
            return null;
        }

        return self::fromArray($data);
    }

    public static function loadFor(string $jobId): ?self
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return null;
        }

        $job = self::load();

        return $job !== null && hash_equals($job->jobId, $jobId) ? $job : null;
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    public function save(): void
    {
        $this->lastUpdateAt = time();
        update_option(self::OPTION, $this->toArray(), false);
    }

    public function isRestore(): bool
    {
        return $this->purpose === self::PURPOSE_RESTORE;
    }

    public function isFinished(): bool
    {
        return $this->phase === self::PHASE_DONE || $this->phase === self::PHASE_FAILED;
    }

    public function isStale(): bool
    {
        return ! $this->isFinished() && (time() - $this->lastUpdateAt) > self::STALE_AFTER;
    }

    /**
     * @return array{ref: string, name: string, size: int}|null
     */
    public function currentEntry(): ?array
    {
        return $this->queue[0] ?? null;
    }

    /**
     * Der aktuelle Eintrag ist durch: raus aus der Warteschlange, Zähler weiter.
     */
    public function completeCurrent(): void
    {
        $fertig = array_shift($this->queue);
        if ($fertig !== null) {
            $this->doneCount++;
            $this->doneBytes += (int) $fertig['size'];
        }

        $this->offset = 0;
        $this->state = [];
    }

    public function finishSuccess(string $message): void
    {
        $this->phase = self::PHASE_DONE;
        $this->message = $message;
        $this->error = '';
        $this->save();
    }

    public function finishFailure(string $error): void
    {
        $this->phase = self::PHASE_FAILED;
        $this->error = $error;
        $this->save();
    }

    public function progressPercent(): int
    {
        if ($this->totalBytes <= 0) {
            return 0;
        }

        return (int) min(100, floor(($this->doneBytes + $this->offset) / $this->totalBytes * 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'spawn_token' => $this->spawnToken,
            'phase' => $this->phase,
            'from_store' => $this->fromStore,
            'to_store' => $this->toStore,
            'delete_after' => $this->deleteAfter,
            'purpose' => $this->purpose,
            'login' => $this->login,
            'restore_name' => $this->restoreName,
            'queue' => $this->queue,
            'offset' => $this->offset,
            'state' => $this->state,
            'done_count' => $this->doneCount,
            'done_bytes' => $this->doneBytes,
            'total_count' => $this->totalCount,
            'total_bytes' => $this->totalBytes,
            'created_at' => $this->createdAt,
            'last_update_at' => $this->lastUpdateAt,
            'message' => $this->message,
            'error' => $this->error,
            'tick_budget' => $this->tickBudget,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['job_id'] ?? ''),
            spawnToken: (string) ($data['spawn_token'] ?? ''),
            phase: (string) ($data['phase'] ?? self::PHASE_RUNNING),
            fromStore: (string) ($data['from_store'] ?? ''),
            toStore: (string) ($data['to_store'] ?? ''),
            deleteAfter: (bool) ($data['delete_after'] ?? false),
            purpose: (string) ($data['purpose'] ?? self::PURPOSE_MOVE),
            login: (string) ($data['login'] ?? ''),
            restoreName: (string) ($data['restore_name'] ?? ''),
            queue: is_array($data['queue'] ?? null) ? array_values($data['queue']) : [],
            offset: (int) ($data['offset'] ?? 0),
            state: is_array($data['state'] ?? null) ? $data['state'] : [],
            doneCount: (int) ($data['done_count'] ?? 0),
            doneBytes: (int) ($data['done_bytes'] ?? 0),
            totalCount: (int) ($data['total_count'] ?? 0),
            totalBytes: (int) ($data['total_bytes'] ?? 0),
            createdAt: (int) ($data['created_at'] ?? time()),
            lastUpdateAt: (int) ($data['last_update_at'] ?? time()),
            message: (string) ($data['message'] ?? ''),
            error: (string) ($data['error'] ?? ''),
            tickBudget: (float) ($data['tick_budget'] ?? 15.0),
        );
    }
}
