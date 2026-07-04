<?php

namespace Database\Seeders;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventSubmissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (EventSubmission::query()->exists()) {
            return;
        }

        EventSubmission::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $events = Event::query()
                    ->with('occurrences:id,event_id')
                    ->get(['id']);
                $userIds = User::query()->pluck('id')->toArray();

                $submissionsToInsert = [];
                $contactsToInsert = [];

                foreach ($events as $event) {
                    $isPublic = random_int(0, 4) === 0;
                    $submitterId = (! $isPublic && ! empty($userIds)) ? $userIds[array_rand($userIds)] : null;

                    $submissionId = (string) Str::uuid();
                    $submitterName = $submitterId ? null : fake()->name();

                    $submissionsToInsert[] = [
                        'id' => $submissionId,
                        'submitter_type' => $submitterId ? User::class : null,
                        'submitter_id' => $submitterId,
                        'target_type' => Event::class,
                        'target_id' => $event->id,
                        'event_id' => $event->id,
                        'event_occurrence_id' => $event->occurrences->first()?->id,
                        'submission_data' => json_encode([
                            'submitter_name' => $submitterName,
                        ], JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                        'submitted_at' => now(),
                        'metadata' => json_encode([
                            'source' => 'database_seed',
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Add contact for public submissions (no user)
                    if (! $submitterId) {
                        $contactsToInsert[] = [
                            'id' => (string) Str::uuid(),
                            'contactable_type' => 'event_submission',
                            'contactable_id' => $submissionId,
                            'type' => ContactMethodType::Email->value,
                            'purpose' => ContactPurpose::General->value,
                            'value' => fake()->safeEmail(),
                            'is_primary' => true,
                            'is_public' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                // Bulk insert submissions
                foreach (array_chunk($submissionsToInsert, 200) as $chunk) {
                    EventSubmission::insert($chunk);
                }

                // Bulk insert contacts
                if ($contactsToInsert !== []) {
                    foreach (array_chunk($contactsToInsert, 200) as $chunk) {
                        DB::table(config('contacting.database.tables.contact_methods', 'contact_methods'))->insert($chunk);
                    }
                }
            });
        } finally {
            EventSubmission::setEventDispatcher(app('events'));
        }
    }
}
