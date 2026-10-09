<?php

namespace Database\Factories;

use App\Models\ShareSourceAttributionReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareSourceAttributionReceipt>
 */
class ShareSourceAttributionReceiptFactory extends Factory
{
    public function definition(): array
    {
        $transitionId = 'user:'.((string) Str::uuid());

        return [
            'namespace' => 'share-outcome',
            'outcome_type' => 'signup',
            'source_transition_id' => $transitionId,
            'business_key' => 'share-outcome:signup:'.$transitionId,
            'attribution_id' => null,
            'provenance' => ShareSourceAttributionReceipt::PROVENANCE_SYSTEM,
            'definition_version' => 'wp2',
            'occurred_at' => now(),
        ];
    }

    public function attributed(?string $attributionId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'attribution_id' => $attributionId ?? ((string) Str::uuid()),
            'provenance' => ShareSourceAttributionReceipt::PROVENANCE_COOKIE,
        ]);
    }
}
