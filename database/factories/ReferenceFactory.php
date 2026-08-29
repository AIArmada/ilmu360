<?php

namespace Database\Factories;

use App\Enums\ReferenceType;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reference>
 */
class ReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(7)),
            'author' => fake()->name(),
            'type' => fake()->randomElement([
                ReferenceType::Book->value,
                ReferenceType::Article->value,
                ReferenceType::Video->value,
            ]),
            'year' => fake()->year(),
            'publisher' => fake()->company(),
            'description' => fake()->paragraph(),
            'is_canonical' => fake()->boolean(),
            'status' => 'verified',
            'published_at' => now(),
        ];
    }

    public function part(?string $type = null): static
    {
        return $this->state(fn (array $attributes) => [
            'part_type' => $type ?? 'jilid',
            'part_number' => fake()->randomDigitNotNull(),
            'part_label' => null,
        ]);
    }

    /**
     * Create a pending reference.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Create a verified reference.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'verified',
        ]);
    }

    /**
     * Create an unpublished reference.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => null,
        ]);
    }
}
