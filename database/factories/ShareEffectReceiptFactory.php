<?php

namespace Database\Factories;

use App\Enums\ShareEffectStatus;
use App\Models\ShareEffectReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareEffectReceipt>
 */
class ShareEffectReceiptFactory extends Factory
{
    public function definition(): array
    {
        $sourceId = 'share-outcome:signup:user:'.((string) Str::uuid());

        return [
            'effect_key' => $sourceId.':projection',
            'source_type' => 'share-outcome',
            'source_id' => $sourceId,
            'effect_type' => 'projection',
            'status' => ShareEffectStatus::Pending,
            'payload' => [],
            'attempts' => 0,
            'lease_token' => null,
            'leased_by' => null,
            'lease_expires_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ];
    }
}
