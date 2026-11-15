<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventShareCardCache
{
    public function read(string $revision): ?string;

    public function publish(string $revision, string $bytes): void;

    public function pruneOlderThan(int $thresholdTimestamp, bool $dryRun): ShareCardCachePruneResult;
}
