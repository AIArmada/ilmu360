<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Ledgers\Support;

use App\Contracts\Rewards\RewardSourceFactReader;
use App\Support\Rewards\RewardSourceFact;

/**
 * Explicit fake source-reader boundary for ledger tests.
 *
 * FIXTURE evidence only: scripted facts let tests exercise real persisted
 * core/ledger transactions (locks, uniques, races) without a production
 * source integration. Passing ledger tests prove domain behavior against
 * scripted facts — never source-integration correctness, which requires the
 * accepted share/claim adapters.
 */
final class FakeRewardSourceFactReader implements RewardSourceFactReader
{
    /**
     * @var array<string, RewardSourceFact>
     */
    private array $facts = [];

    /**
     * @var list<string>
     */
    private array $reads = [];

    /**
     * @var array<string, int>
     */
    private array $readCounts = [];

    public function __construct(
        /**
         * @var array<string, list<RewardSourceFact|null>> scripted per-key read sequences.
         */
        private array $scripts = [],
    ) {}

    public function seed(RewardSourceFact $fact): void
    {
        $this->facts[$fact->sourceBusinessKey] = $fact;
    }

    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        $this->reads[] = $sourceBusinessKey;
        $this->readCounts[$sourceBusinessKey] = ($this->readCounts[$sourceBusinessKey] ?? 0) + 1;

        if (array_key_exists($sourceBusinessKey, $this->scripts) && $this->scripts[$sourceBusinessKey] !== []) {
            return array_shift($this->scripts[$sourceBusinessKey]);
        }

        return $this->facts[$sourceBusinessKey] ?? null;
    }

    /**
     * @return list<string>
     */
    public function readKeys(): array
    {
        return $this->reads;
    }

    public function readCount(string $sourceBusinessKey): int
    {
        return $this->readCounts[$sourceBusinessKey] ?? 0;
    }
}
