<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Immutable aggregates over the one qualified, proven share-outcome set.
 *
 * @phpstan-type CellEvidence array{outcomes: int, sharers: int, participants: int}
 */
final readonly class ShareOutcomeEvidence
{
    public const string UNKNOWN_CHANNEL = 'unknown';

    /**
     * @param  array<string, array{outcomes: int, sharers: int, participants: int}>  $byChannel
     * @param  array<string, array{outcomes: int, sharers: int, participants: int}>  $byOutcome
     */
    public function __construct(
        public int $outcomeCount,
        public int $distinctSharers,
        public int $distinctParticipants,
        public array $byChannel,
        public array $byOutcome,
        public string $definitionVersion,
    ) {}
}
