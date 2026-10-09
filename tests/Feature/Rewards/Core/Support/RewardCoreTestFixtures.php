<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Core\Support;

use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardPresetBuilders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Shared valid payloads for rewards-core tests. Preset rules/terms are used
 * as validator-clean starting points, never as activation claims.
 */
final class RewardCoreTestFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        /** @var array<string, mixed> $rules */
        $rules = RewardPresetBuilders::for($mode)['rules'];

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public static function terms(RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        /** @var array<string, mixed> $terms */
        $terms = RewardPresetBuilders::for($mode)['terms'];

        return $terms;
    }

    /**
     * @return array{0: RewardProgram, 1: RewardProgramVersion}
     */
    public static function seedVersionedProgram(string $prefix, RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        $program = RewardProgram::factory()->create([
            'family_key' => $prefix.'-'.Str::lower(Str::random(6)),
            'mode' => $mode,
        ]);
        $effective = CarbonImmutable::parse('2026-06-01T00:00:00Z');
        $version = PublishRewardProgramVersionAction::run(
            $program,
            $effective,
            self::rules($mode),
            self::terms($mode),
            null,
            null,
            null,
            $effective,
        );

        return [$program->refresh(), $version];
    }
}
