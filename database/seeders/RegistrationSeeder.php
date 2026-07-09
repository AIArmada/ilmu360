<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                    ->whereHas('settings', function ($query) {
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

                        $registration = new Registration([
                            'event_id' => $eventId,
                            'registrant_type' => isset($user['id']) ? (new User)->getMorphClass() : null,
                            'registrant_id' => $user['id'] ?? null,
                            'registration_type' => 'individual',
                            'status' => 'confirmed',
                            'source' => 'website',
                            'total_participants' => 1,
                        ]);
                        $registration
                            ->stagePrimaryParticipant(
                                $user['name'] ?? fake()->name(),
                                $email,
                                $user['phone'] ?? fake()->optional()->phoneNumber(),
                            )
                            ->save();
                    }
                }

                // Bulk update registration counts
                foreach ($eventCounts as $eventId => $count) {
                    $event = Event::query()->find($eventId);

                    if ($event instanceof Event) {
                        $event->registrations_count = $count;
                        $event->saveQuietly();
                    }
                }
            });
        } finally {
            Event::setEventDispatcher(app('events'));
            Registration::setEventDispatcher(app('events'));
        }
    }
}
