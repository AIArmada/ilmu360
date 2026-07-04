<?php

declare(strict_types=1);

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventRegistrationFactory as PackageEventRegistrationFactory;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Str;

class RegistrationFactory extends PackageEventRegistrationFactory
{
    protected $model = Registration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'status' => fake()->randomElement(['confirmed', 'cancelled', 'completed', 'no_show']),
            'metadata' => array_filter([
                'primary_participant' => array_filter([
                    'name' => fake()->name(),
                    'contact' => array_filter([
                        'email' => fake()->optional()->safeEmail(),
                        'phone' => fake()->optional()->phoneNumber(),
                    ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
                'checkin_token' => fake()->boolean(10) ? Str::random(40) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    public function forRegistrant(User $user): static
    {
        return $this
            ->state(fn (): array => [
                'registrant_type' => $user->getMorphClass(),
                'registrant_id' => $user->getKey(),
            ])
            ->afterMaking(function ($registration) use ($user): void {
                if (! $registration instanceof Registration) {
                    return;
                }

                $registration->stagePrimaryParticipant($user->name, $user->email, $user->phone);
            });
    }

    public function withPrimaryParticipant(string $name, ?string $email = null, ?string $phone = null): static
    {
        return $this->afterMaking(function ($registration) use ($email, $name, $phone): void {
            if (! $registration instanceof Registration) {
                return;
            }

            $registration->stagePrimaryParticipant($name, $email, $phone);
        });
    }

    public function withCheckinToken(?string $checkinToken = null): static
    {
        return $this->afterMaking(function ($registration) use ($checkinToken): void {
            if (! $registration instanceof Registration) {
                return;
            }

            $registration->setCheckinToken($checkinToken ?? Str::random(40));
        });
    }
}
