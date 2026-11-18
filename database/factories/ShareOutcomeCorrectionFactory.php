<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\ShareTracking\Models\ShareOutcomeCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareOutcomeCorrection>
 */
class ShareOutcomeCorrectionFactory extends Factory
{
    protected $model = ShareOutcomeCorrection::class;

    public function definition(): array
    {
        return [
            'conversion_id' => (string) Str::uuid(),
            'business_key' => 'share-outcome:signup:user:'.(Str::uuid()),
            'reason' => 'registration_cancelled',
            'actor_type' => null,
            'actor_id' => null,
            'occurred_at' => now(),
        ];
    }
}
