<?php

declare(strict_types=1);

namespace App\Features\Rewards\Support;

use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardVersionResolver;
use App\Features\Rewards\Api\PublishedProgramVersions;
use App\Features\Rewards\Api\PublishedProgramVersionSnapshot;

/**
 * Shared-boundary adapter over Rewards' published-version timeline resolver.
 *
 * External composition receives only the version id and eligibility rules.
 */
final readonly class PublishedProgramVersionsAdapter implements PublishedProgramVersions
{
    public function publishedVersion(string $programId): ?PublishedProgramVersionSnapshot
    {
        $program = RewardProgram::query()->find($programId);

        if (! $program instanceof RewardProgram) {
            return null;
        }

        $version = RewardVersionResolver::resolveCurrentForProgram($program);

        if (! $version instanceof RewardProgramVersion) {
            return null;
        }

        $eligibility = $version->rules['eligibility'] ?? null;

        return new PublishedProgramVersionSnapshot(
            (string) $version->getKey(),
            is_array($eligibility) ? $eligibility : null,
        );
    }
}
