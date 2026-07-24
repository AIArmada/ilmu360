<?php

use App\Enums\EventFormat;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates an advanced event with an institution primary organizer', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Test', 'status' => 'verified']);

    $user->institutions()->syncWithoutDetaching([$institution->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.client.advanced-events.store'), [
        'title' => 'Kuliah Maghrib Ramadan',
        'description' => 'Kuliah maghrib bulan Ramadan',
        'timezone' => 'Asia/Kuala_Lumpur',
        'program_starts_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        'program_ends_at' => now()->addDays(7)->addHours(2)->format('Y-m-d H:i:s'),
        'primary_organizer_id' => (string) $institution->getKey(),
        'default_event_category_ids' => [eventCategoryId('other')],
        'default_event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'registration_required' => false,
        'registration_mode' => RegistrationScope::Event->value,
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => ['event' => ['id', 'slug', 'title', 'status']],
            'meta' => ['request_id'],
        ]);

    expect($response->json('data.event.title'))->toBe('Kuliah Maghrib Ramadan')
        ->and($response->json('data.event.status'))->toBe('draft');
});

it('creates an advanced event with a person primary organizer', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjekt Test', 'status' => 'verified']);
    $person = Person::factory()->create(['name' => 'Ustaz Test', 'status' => 'verified']);

    $user->institutions()->syncWithoutDetaching([$institution->id]);
    $user->persons()->syncWithoutDetaching([$person->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.client.advanced-events.store'), [
        'title' => 'Kuliah Subuh',
        'timezone' => 'Asia/Kuala_Lumpur',
        'program_starts_at' => now()->addDays(14)->format('Y-m-d H:i:s'),
        'program_ends_at' => now()->addDays(14)->addHours(1)->format('Y-m-d H:i:s'),
        'primary_organizer_id' => (string) $person->getKey(),
        'location_institution_id' => (string) $institution->getKey(),
        'default_event_category_ids' => [eventCategoryId('other')],
        'default_event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'registration_required' => false,
        'registration_mode' => RegistrationScope::Event->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.event.title', 'Kuliah Subuh');
});

it('rejects advanced event creation when not authenticated', function () {
    $response = $this->postJson(route('api.client.advanced-events.store'), [
        'title' => 'Test',
    ]);

    $response->assertUnauthorized();
});

it('rejects advanced event creation with invalid primary organizer', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Test', 'status' => 'verified']);

    $user->institutions()->syncWithoutDetaching([$institution->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.client.advanced-events.store'), [
        'title' => 'Kuliah Maghrib',
        'timezone' => 'Asia/Kuala_Lumpur',
        'program_starts_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        'program_ends_at' => now()->addDays(7)->addHours(2)->format('Y-m-d H:i:s'),
        'primary_organizer_id' => (string) Str::uuid(),
        'default_event_category_ids' => [eventCategoryId('other')],
        'default_event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'registration_required' => false,
        'registration_mode' => RegistrationScope::Event->value,
    ]);

    $response->assertForbidden();
});
