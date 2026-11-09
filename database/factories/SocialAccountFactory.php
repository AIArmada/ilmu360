<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Identity\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => $this->faker->randomElement(['google', 'facebook', 'github']),
            'provider_id' => (string) $this->faker->numberBetween(100000, 999999999),
            'avatar_url' => $this->faker->optional()->imageUrl(256, 256),
        ];
    }
}
