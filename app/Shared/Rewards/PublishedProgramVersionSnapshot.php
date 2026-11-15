<?php

declare(strict_types=1);

namespace App\Shared\Rewards;

/** Immutable published-version data exposed to cross-feature enrollment composition. */
final readonly class PublishedProgramVersionSnapshot
{
    /** @param array<string, mixed>|null $eligibility */
    public function __construct(
        public string $versionId,
        public ?array $eligibility,
    ) {}
}
