<?php

use App\Models\Event;
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
        ->assertJsonPath('data.event.title', 'API References Event');

    $event = Event::query()->where('title', 'API References Event')->firstOrFail();

    expect($event->references()->count())->toBe(1)
        ->and((string) $event->references()->firstOrFail()->getKey())->toBe((string) $reference->getKey());
});
