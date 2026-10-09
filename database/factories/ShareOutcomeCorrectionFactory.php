<?php

namespace Database\Factories;

use App\Models\ShareOutcomeCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareOutcomeCorrection>
 */
class ShareOutcomeCorrectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversion_id' => (string) Str::uuid(),
            'business_key' => 'share-outcome:signup:user:'.((string) Str::uuid()),
            'reason' => 'registration_cancelled',
            'actor_type' => null,
            'actor_id' => null,
            'occurred_at' => now(),
        ];
    }
}
