<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardProgramStatus;
use Carbon\CarbonImmutable;

/**
 * Point-in-time policy snapshot for preview evaluation.
 *
 * Clock plan (explicit):
 * - occurrence time: fixture occurredAt — when the source evidence happened.
 *   It determines factual evidence, attribution, and lookback eligibility.
 * - decision time: first legitimate entitlement decision moment (authorized
 *   earning/claim/enrollment decision after required acceptance). Policy
 *   applicability (latest version with effective_from <= decision) and
 *   acceptance coverage are computed against this time, never against "now"
 *   and never against occurrence alone.
 * - evaluation time is not an input: the engine is pure and deterministic.
 *   Live evaluation must additionally re-check freshness under locks.
 */
final readonly class RewardPreviewContext
{
    /**
     * @param  array<string, mixed>  $versionRules  Frozen validated rules snapshot.
     * @param  list<string>  $versionComponents  Enabled component values.
     * @param  list<string>  $compatibleWith  Frozen stacking allowlist of the applicable version.
     */
    public function __construct(
        public string $programFamilyKey,
        public RewardProgramStatus $programStatus,
        public bool $versionFound,
        public ?string $versionId,
        public ?int $versionNumber,
        public array $versionRules,
        public array $versionComponents,
        public string $versionMaterialHash,
        public ?CarbonImmutable $versionEffectiveFrom,
        public array $compatibleWith,
        public bool $enrollmentExists,
        public bool $enrollmentActiveAtDecision,
        public ?string $acceptanceId,
        public ?string $acceptanceMaterialHash,
        public ?CarbonImmutable $acceptanceAcceptedAt,
        public bool $hasPriorAcceptance,
        public ?CarbonImmutable $relevantPeriodJoinedAt,
        public RewardPreviewFixture $fixture,
        public RewardComponent $requestedComponent,
        public CarbonImmutable $decisionTime,
    ) {}
}
