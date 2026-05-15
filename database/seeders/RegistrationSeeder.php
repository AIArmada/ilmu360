<?php

namespace Database\Seeders;

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

                $registrationsToInsert = [];
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

                        $registrationsToInsert[] = array_merge(
                            Registration::factory()->make([
                                'event_id' => $eventId,
                                'user_id' => $user['id'] ?? null,
                                'name' => $user['name'] ?? fake()->name(),
                                'email' => $email,
                                'phone' => $user['phone'] ?? fake()->optional()->phoneNumber(),
                                'status' => 'registered',
                            ])->toArray(),
                            [
                                'id' => (string) Str::uuid(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }

                foreach (array_chunk($registrationsToInsert, 200) as $chunk) {
                    Registration::insert($chunk);
                }

                // Bulk update registration counts
                foreach ($eventCounts as $eventId => $count) {
                    Event::where('id', $eventId)->update(['registrations_count' => $count]);
                }
            });
        } finally {
            Event::setEventDispatcher(app('events'));
            Registration::setEventDispatcher(app('events'));
        }
    }
}
