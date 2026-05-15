<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationCadence;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\PendingNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingNotification>
 */
class PendingNotificationFactory extends Factory
{
    protected $model = PendingNotification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fingerprint' => $this->faker->unique()->sha1(),
            'family' => NotificationFamily::EventUpdates->value,
            'trigger' => NotificationTrigger::EventScheduleChanged->value,
            'title' => 'Majlis updated',
            'body' => 'There is an update to your tracked event.',
            'action_url' => '/events/example',
            'entity_type' => 'event',
            'entity_id' => $this->faker->uuid(),
            'priority' => NotificationPriority::Medium->value,
            'delivery_cadence' => NotificationCadence::Instant->value,
            'occurred_at' => now()->subHour(),
            'read_at' => null,
            'channels_attempted' => ['in_app', 'email'],
            'meta' => [],
            'processed_at' => null,
            'dispatched_at' => null,
            'notification_id' => null,
        ];
    }
}
