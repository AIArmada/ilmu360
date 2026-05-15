<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventChangeAnnouncement>
 */
class EventChangeAnnouncementFactory extends Factory
{
    protected $model = EventChangeAnnouncement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'replacement_event_id' => null,
            'actor_id' => User::factory(),
            'type' => EventChangeType::ScheduleChanged,
            'status' => EventChangeStatus::Published,
            'severity' => EventChangeSeverity::High,
            'public_message' => fake()->sentence(),
            'internal_note' => null,
            'changed_fields' => ['starts_at'],
            'before_snapshot' => [],
            'after_snapshot' => [],
            'published_at' => now(),
            'retracted_at' => null,
        ];
    }
}
