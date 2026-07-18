<?php

namespace Database\Seeders;

use AIArmada\Contacting\Data\ContactMethodData;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RegistrationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Registration::query()->exists()) {
            return;
        }

        Registration::unsetEventDispatcher();
        Event::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $events = Event::query()
                    ->whereHas('accessPolicy', function ($query): void {
                        $query->where('registration_required', true);
                    })
                    ->pluck('id')
                    ->toArray();

                $userColumns = ['id', 'name', 'email'];

                if (Schema::hasColumn('users', 'phone')) {
                    $userColumns[] = 'phone';
                }

                $users = User::query()->get($userColumns)->toArray();

                $eventCounts = [];

                foreach ($events as $eventId) {
                    $count = random_int(3, 8);
                    $eventCounts[$eventId] = $count;
                    $shuffledUsers = collect($users)->shuffle()->values()->toArray();
                    $usedEmails = [];
                    $userIndex = 0;

                    for ($i = 0; $i < $count; $i++) {
                        $user = null;
                        if (! empty($shuffledUsers) && $userIndex < count($shuffledUsers) && random_int(0, 1) === 1) {
                            $user = $shuffledUsers[$userIndex++];
                        }

                        $email = $user['email'] ?? fake()->safeEmail();
                        while (in_array($email, $usedEmails, true)) {
                            $user = null;
                            $email = fake()->safeEmail();
                        }
                        $usedEmails[] = $email;

                        // Event dispatcher is unset for bulk seed speed; assign package defaults explicitly.
                        $registration = new Registration([
                            'registration_no' => 'REG-'.mb_strtoupper(Str::random(10)),
                            'registered_at' => now(),
                            'event_id' => $eventId,
                            'registrant_type' => isset($user['id']) ? (new User)->getMorphClass() : null,
                            'registrant_id' => $user['id'] ?? null,
                            'registration_type' => 'individual',
                            'status' => 'confirmed',
                            'source' => 'website',
                            'total_participants' => 1,
                        ]);
                        $registration->id = (string) Str::uuid();
                        $registration->save();

                        $participant = $registration->participants()->create([
                            'id' => (string) Str::uuid(),
                            'event_id' => $registration->event_id,
                            'event_occurrence_id' => $registration->event_occurrence_id,
                            'event_session_id' => $registration->event_session_id,
                            'participant_type' => $registration->registrant_type,
                            'participant_id' => $registration->registrant_id,
                            'name' => $user['name'] ?? fake()->name(),
                            'is_primary' => true,
                            'is_purchaser' => true,
                            'status' => 'active',
                        ]);

                        $participant->addContactMethod(new ContactMethodData(
                            type: 'email',
                            purpose: 'general',
                            value: $email,
                            isPrimary: true,
                        ));

                        $phone = $user['phone'] ?? null;

                        if (is_string($phone) && $phone !== '') {
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

            });
        } finally {
            Event::setEventDispatcher(app('events'));
            Registration::setEventDispatcher(app('events'));
        }
    }
}
