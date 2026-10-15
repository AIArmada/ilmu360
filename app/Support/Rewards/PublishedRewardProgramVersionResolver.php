<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardVersionResolver;
use Carbon\CarbonImmutable;

final class PublishedRewardProgramVersionResolver
{
    public function resolve(RewardProgram $program): ?RewardProgramVersion
    {
        $versions = RewardProgramVersion::query()
            ->where('program_id', $program->getKey())
            ->orderBy('version')
            ->get();
        $timeline = [];

        foreach ($versions as $version) {
            $timeline[] = [
                'id' => (string) $version->getKey(),
                'version' => (int) $version->version,
                'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
            ];
        }

        $applicable = RewardVersionResolver::resolveAt($timeline, CarbonImmutable::now('UTC'));

        if ($applicable === null) {
            return null;
        }

        foreach ($versions as $version) {
            if ((string) $version->getKey() === $applicable['id']) {
                return $version;
            }
        }

        return null;
    }
}
