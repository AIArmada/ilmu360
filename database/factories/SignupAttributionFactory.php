<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SignupAttributionSourceType;
use App\Enums\SignupAttributionStatus;
use App\Models\SignupAttribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SignupAttribution> */
class SignupAttributionFactory extends Factory
{
    protected $model = SignupAttribution::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_type' => SignupAttributionSourceType::Direct,
            'status' => SignupAttributionStatus::Confirmed,
            'signed_up_at' => now(),
            'confirmed_at' => now(),
        ];
    }
}
