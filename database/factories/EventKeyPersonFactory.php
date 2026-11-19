<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Persons\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventKeyPerson>
 */
class EventKeyPersonFactory extends Factory
{
    #[\Override]
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
