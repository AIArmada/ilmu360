<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contracts\EventCategoryCatalog;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $radius = fake()->boolean(40) ? fake()->numberBetween(1, 50) : null;

        return [
            'user_id' => User::factory(),
            'user_type' => (new User)->getMorphClass(),
            'name' => fake()->words(3, true),
            'query' => fake()->optional()->words(2, true),
            'filters' => fake()->boolean(50)
                ? [
                    'event_category_ids' => [array_key_first(app(EventCategoryCatalog::class)->options())],
                    'language_codes' => [fake()->randomElement(['ms', 'en', 'ar'])],
                ]
                : null,
            'radius_km' => $radius,
            'lat' => $radius ? fake()->randomFloat(7, 1.0, 7.0) : null,
            'lng' => $radius ? fake()->randomFloat(7, 99.0, 119.0) : null,
            'notify' => fake()->randomElement(['off', 'instant', 'daily', 'weekly']),
        ];
    }
}
