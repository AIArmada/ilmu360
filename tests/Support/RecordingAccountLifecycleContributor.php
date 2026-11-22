<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Auth\AccountLifecycleContributor;

final class RecordingAccountLifecycleContributor implements AccountLifecycleContributor
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $snapshotData
     */
    public function __construct(
        private readonly string $key,
        private readonly int $priority,
        private readonly array $snapshotData = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function snapshot(string $accountId): array
    {
        $this->calls[] = "snapshot:{$accountId}";

        return $this->snapshotData;
    }

    public function detach(string $accountId): void
    {
        $this->calls[] = "detach:{$accountId}";
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->calls[] = "restore:{$accountId}:".json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    public function afterRestore(string $accountId): void
    {
        $this->calls[] = "afterRestore:{$accountId}";
    }
}
