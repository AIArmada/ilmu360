<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDestinationStatus;
use App\Models\NotificationDestination;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDestination>
 */
class NotificationDestinationFactory extends Factory
{
    protected $model = NotificationDestination::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => NotificationChannel::Push->value,
            'address' => $this->faker->uuid(),
            'external_id' => $this->faker->sha1(),
            'status' => NotificationDestinationStatus::Active->value,
            'is_primary' => false,
            'verified_at' => now(),
            'meta' => [
                'platform' => 'ios',
                'device_label' => 'iPhone',
                'last_seen_at' => now()->toIso8601String(),
            ],
        ];
    }
}
