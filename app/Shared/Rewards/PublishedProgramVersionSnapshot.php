<?php

declare(strict_types=1);

namespace App\Shared\Rewards;

/**
 * Immutable published-version data exposed to cross-feature enrollment composition.
 *
 * @param  array<string, mixed>|null  $eligibility
 */
final readonly class PublishedProgramVersionSnapshot
{
    public function __construct(
        public string $versionId,
        public ?array $eligibility,
    ) {}
}
