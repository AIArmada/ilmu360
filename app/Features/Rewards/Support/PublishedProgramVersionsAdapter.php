<?php

declare(strict_types=1);

namespace App\Features\Rewards\Support;

use AIArmada\Rewards\Models\RewardProgram;
use App\Shared\Rewards\PublishedProgramVersions;
use App\Shared\Rewards\PublishedProgramVersionSnapshot;

/**
 * Shared-boundary adapter over Rewards' published-version timeline resolver.
 *
 * External composition receives only the version id and eligibility rules.
 */
final readonly class PublishedProgramVersionsAdapter implements PublishedProgramVersions
{
    public function __construct(
        private PublishedRewardProgramVersionResolver $resolver,
    ) {}

    public function publishedVersion(string $programId): ?PublishedProgramVersionSnapshot
    {
        $program = RewardProgram::query()->find($programId);

        if (! $program instanceof RewardProgram) {
            return null;
        }

        $version = $this->resolver->resolve($program);

        if (! $version instanceof \AIArmada\Rewards\Models\RewardProgramVersion) {
            return null;
        }

        $eligibility = $version->rules['eligibility'] ?? null;

        return new PublishedProgramVersionSnapshot(
            (string) $version->getKey(),
            is_array($eligibility) ? $eligibility : null,
        );
    }
}
