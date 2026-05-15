<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationFamily;
use App\Enums\NotificationTrigger;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\PendingNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'notification_message_id' => PendingNotification::factory(),
            'user_id' => User::factory(),
            'family' => NotificationFamily::EventUpdates->value,
            'trigger' => NotificationTrigger::EventScheduleChanged->value,
            'channel' => NotificationChannel::Email->value,
            'destination_id' => NotificationDestination::factory(),
            'fingerprint' => $this->faker->unique()->sha1(),
            'provider' => 'mail',
            'provider_message_id' => $this->faker->uuid(),
            'status' => NotificationDeliveryStatus::Delivered->value,
            'payload' => [],
            'meta' => [],
            'sent_at' => now()->subMinutes(5),
            'delivered_at' => now()->subMinutes(5),
            'failed_at' => null,
        ];
    }
}
