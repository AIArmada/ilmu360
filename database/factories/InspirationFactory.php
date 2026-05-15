<?php

namespace Database\Factories;

use App\Enums\InspirationCategory;
use App\Models\Inspiration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inspiration>
 */
class InspirationFactory extends Factory
{
    protected $model = Inspiration::class;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function definition(): array
    {
        $category = fake()->randomElement(InspirationCategory::cases());

        return [
            'category' => $category,
            'locale' => 'ms',
            'title' => fake()->sentence(4),
            'content' => fake()->paragraph(),
            'source' => fake()->optional(0.7)->name(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function category(InspirationCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function locale(string $locale): static
    {
        return $this->state(fn (): array => ['locale' => $locale]);
    }
}
