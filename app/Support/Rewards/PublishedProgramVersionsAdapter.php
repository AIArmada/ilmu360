<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Models\RewardProgram;
use App\Shared\Rewards\PublishedProgramVersions;

/**
 * Root adapter over the established published-version timeline resolver.
 *
 * The resolver stays root because it reads the rewards package version model;
 * feature code receives only the scalar version id.
 */
final readonly class PublishedProgramVersionsAdapter implements PublishedProgramVersions
{
    public function __construct(
        private PublishedRewardProgramVersionResolver $resolver,
    ) {}

    public function publishedVersionId(string $programId): ?string
    {
        $program = RewardProgram::query()->find($programId);

        if (! $program instanceof RewardProgram) {
            return null;
        }

        $version = $this->resolver->resolve($program);

        return $version === null ? null : (string) $version->getKey();
    }
}
