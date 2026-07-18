<?php

declare(strict_types=1);

namespace Database\Factories;

use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Events\Database\Factories\EventRegistrationFactory as PackageEventRegistrationFactory;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;

class RegistrationFactory extends PackageEventRegistrationFactory
{
    protected $model = Registration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'registration_type' => 'individual',
            'status' => fake()->randomElement(['confirmed', 'cancelled', 'completed', 'no_show']),
            'source' => 'website',
            'total_participants' => 1,
        ];
    }

    public function forRegistrant(User $user): static
    {
        return $this
            ->state(fn (): array => [
                'registrant_type' => $user->getMorphClass(),
                'registrant_id' => $user->getKey(),
            ])
            ->afterCreating(function ($registration) use ($user): void {
                if (! $registration instanceof Registration) {
                    return;
                }

                $this->persistPrimaryParticipant($registration, $user->name, $user->email, $user->phone);
            });
    }

    public function withPrimaryParticipant(string $name, ?string $email = null, ?string $phone = null): static
    {
        return $this->afterCreating(function ($registration) use ($email, $name, $phone): void {
            if (! $registration instanceof Registration) {
                return;
            }

            $this->persistPrimaryParticipant($registration, $name, $email, $phone);
        });
    }

    private function persistPrimaryParticipant(Registration $registration, string $name, ?string $email, ?string $phone): void
    {
        $participant = $registration->participants()->create([
            'event_id' => $registration->event_id,
            'event_occurrence_id' => $registration->event_occurrence_id,
            'event_session_id' => $registration->event_session_id,
            'participant_type' => $registration->registrant_type,
            'participant_id' => $registration->registrant_id,
            'name' => $name,
            'is_primary' => true,
            'is_purchaser' => true,
            'status' => 'active',
        ]);

        if ($email !== null && $email !== '') {
            $participant->addContactMethod(new ContactMethodData(
                type: 'email',
                purpose: 'general',
                value: $email,
                isPrimary: true,
            ));
        }

        if ($phone !== null && $phone !== '') {
            $participant->addContactMethod(new ContactMethodData(
                type: 'phone',
                purpose: 'general',
                value: $phone,
                countryCode: config('contacting.defaults.country_code', 'MY'),
                isPrimary: true,
            ));
        }
    }
}
