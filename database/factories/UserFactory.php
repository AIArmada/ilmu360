<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attributes = [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];

        if (Schema::hasColumn('users', 'phone')) {
            $attributes['phone'] = fake()->unique()->phoneNumber();
        }

        if (Schema::hasColumn('users', 'phone_verified_at')) {
            $attributes['phone_verified_at'] = now();
        }

        return $attributes;
    }

    /**
     * Indicate that the user only has a phone number (no email).
     */
    public function phoneOnly(): static
    {
        return $this->state(function (array $attributes): array {
            $state = [
                'email' => null,
                'email_verified_at' => null,
            ];

            if (Schema::hasColumn('users', 'phone')) {
                $state['phone'] = fake()->unique()->phoneNumber();
            }

            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $state['phone_verified_at'] = now();
            }

            return $state;
        });
    }

    /**
     * Indicate that the user only has an email (no phone).
     */
    public function emailOnly(): static
    {
        return $this->state(function (array $attributes): array {
            $state = [];

            if (Schema::hasColumn('users', 'phone')) {
                $state['phone'] = null;
            }

            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $state['phone_verified_at'] = null;
            }

            return $state;
        });
    }

    /**
     * Configure the model factory.
     */
    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function ($user) {
            //
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
