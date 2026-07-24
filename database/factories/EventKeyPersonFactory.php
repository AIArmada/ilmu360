<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventKeyPerson>
 */
class EventKeyPersonFactory extends Factory
{
    protected $model = EventKeyPerson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'involveable_type' => 'person',
            'involveable_id' => Person::factory(),
            'role_code' => EventKeyPersonRole::Speaker->value,
            'visibility' => 'public',
            'sort_order' => 1,
            'notes' => null,
        ];
    }
}
