<?php

use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

/**
 * @return array<string, mixed>
 */
function submitApiReferencesPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'API References Event',
        'description' => 'API references persistence test.',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [languageId('ms')],
    ], $overrides);
}

it('persists validated references for new-event api submissions', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $reference = Reference::factory()->create(['status' => 'verified']);

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.submit-event.store'), submitApiReferencesPayload([
        'primary_organizer_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'references' => [$reference->getKey()],
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.occurrence.title', 'API References Event');

    $occurrence = submittedOccurrenceByTitle('API References Event');

    expect($occurrence->references()->count())->toBe(1)
        ->and((string) $occurrence->references()->firstOrFail()->referenceable_id)->toBe((string) $reference->getKey());
});
