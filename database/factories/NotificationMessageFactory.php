<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Notifications\NotificationCenterMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationMessage>
 */
class NotificationMessageFactory extends Factory
{
    protected $model = NotificationMessage::class;

    public function definition(): array
    {
        return [
            'id' => (string) fake()->uuid(),
            'type' => NotificationCenterMessage::class,
            'notifiable_type' => (new User)->getMorphClass(),
            'notifiable_id' => User::factory(),
            'data' => [
                'family' => NotificationFamily::EventUpdates->value,
                'trigger' => NotificationTrigger::EventScheduleChanged->value,
                'title' => 'Majlis updated',
                'body' => 'There is an update to your tracked event.',
                'action_url' => '/events/example',
                'entity_type' => 'event',
                'entity_id' => fake()->uuid(),
                'priority' => NotificationPriority::Medium->value,
                'occurred_at' => now()->subHour()->toIso8601String(),
                'channels_attempted' => ['in_app', 'email'],
                'meta' => [],
            ],
            'family' => NotificationFamily::EventUpdates->value,
            'trigger' => NotificationTrigger::EventScheduleChanged->value,
            'priority' => NotificationPriority::Medium->value,
            'fingerprint' => fake()->unique()->sha1(),
            'action_url' => '/events/example',
            'entity_type' => 'event',
            'entity_id' => fake()->uuid(),
            'occurred_at' => now()->subHour(),
            'inbox_visible' => true,
            'is_digest' => false,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
