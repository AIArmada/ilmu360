<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;

final readonly class RewardPreviewResult
{
    /**
     * @param  list<RewardPreviewReason>  $reasons  Fixed gate order; deterministic.
     * @param  list<string>  $explanations  Human-readable, aligned with reasons.
     */
    public function __construct(
        public RewardPreviewDecision $decision,
        public array $reasons,
        public array $explanations,
        public ?string $applicableVersionId,
        public ?string $acceptanceId,
        public string $evidenceLabel = RewardPreviewFixture::FIXTURE_LABEL,
    ) {}

    public function isEligible(): bool
    {
        return $this->decision === RewardPreviewDecision::Eligible;
    }
}
