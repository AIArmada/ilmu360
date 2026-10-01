<?php

namespace Database\Factories;

use AIArmada\References\Enums\ReferenceContributorRole;
use App\Enums\ReferenceType;
use App\Models\Person;
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
            'type' => fake()->randomElement([
                ReferenceType::Book->value,
                ReferenceType::Article->value,
                ReferenceType::Video->value,
            ]),
            'year' => fake()->year(),
            'publisher' => fake()->company(),
            'description' => fake()->paragraph(),
            'record_kind' => 'work',
            'isbn' => fake()->isbn13(),
            'language' => null,
            'status' => 'verified',
            'published_at' => now(),
        ];
    }

    public function part(?string $type = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ReferenceType::Book->value,
            'record_kind' => 'part',
            'part_type' => $type ?? 'jilid',
            'part_number' => fake()->randomDigitNotNull(),
            'part_label' => null,
        ]);
    }

    public function edition(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ReferenceType::Book->value,
            'record_kind' => 'edition',
            'edition_number' => 1,
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

    /**
     * Attach authors to a work (editions and parts inherit from their root work,
     * so links on children are ignored).
     *
     * @param  list<string>|int|null  $authors
     */
    public function withAuthors(array|int|null $authors = null): static
    {
        return $this->afterCreating(function (Reference $reference) use ($authors): void {
            if (! $reference->isRootReference()) {
                return;
            }

            $authorIds = is_array($authors)
                ? $authors
                : Person::factory()->count($authors ?? 1)->create()
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->values()
                    ->all();

            $reference->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), $authorIds);
        });
    }
}
