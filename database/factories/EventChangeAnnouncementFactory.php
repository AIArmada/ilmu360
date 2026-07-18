<?php

declare(strict_types=1);

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventUpdateFactory;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\User;

class EventChangeAnnouncementFactory extends EventUpdateFactory
{
    protected $model = EventChangeAnnouncement::class;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function definition(): array
    {
        $message = fake()->sentence();

        return [
            'event_id' => Event::factory(),
            'replacement_event_id' => null,
            'created_by_type' => User::class,
            'created_by_id' => User::factory(),
            'update_type' => EventChangeType::ScheduleChanged,
            'title' => $message,
            'message' => $message,
            'notes' => null,
            'severity' => EventChangeSeverity::High,
            'visibility' => 'public',
            'metadata' => [
                'status' => 'published',
                'changed_fields' => ['starts_at'],
                'before_snapshot' => [],
                'after_snapshot' => [],
            ],
            'published_at' => now(),
            'archived_at' => null,
        ];
    }
}
