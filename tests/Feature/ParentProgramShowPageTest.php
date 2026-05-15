<?php

use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders a dedicated public parent program page with only public child events', function () {
    $institution = Institution::factory()->create(['name' => 'Masjid Al-Hidayah']);

    $parentEvent = Event::factory()->parentProgram()->for($institution)->create([
        'title' => 'Ramadan Umbrella Program',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(10),
    ]);

    Event::factory()->childEvent($parentEvent)->for($institution)->create([
        'title' => 'Approved Child Event',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
    ]);

    Event::factory()->childEvent($parentEvent)->for($institution)->create([
        'title' => 'Draft Child Event',
        'status' => 'draft',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHours(2),
    ]);

    $this->get(route('events.show', $parentEvent))
        ->assertSuccessful()
        ->assertSee('Parent Program')
        ->assertSee('Program Schedule')
        ->assertSee('Approved Child Event')
        ->assertDontSee('Draft Child Event')
        ->assertDontSee('Check-in');
});

it('shows a create-first-child call to action to owners on empty parent program pages', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Al-Hidayah']);

    $institution->members()->syncWithoutDetaching([$owner->id]);

    $parentEvent = Event::factory()->parentProgram()->for($institution)->create([
        'user_id' => $owner->id,
        'submitter_id' => $owner->id,
        'title' => 'Draft Umbrella Program',
        'status' => 'draft',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(10),
    ]);

    $this->actingAs($owner)
        ->get(route('events.show', $parentEvent))
        ->assertSuccessful()
        ->assertSee('No public child events are available for this program yet.')
        ->assertSee('Create First Child Event')
        ->assertSee(route('submit-event.create', ['parent' => $parentEvent]), false)
        ->assertSee(AhliEventResource::getUrl('view', ['record' => $parentEvent], panel: 'ahli'), false);
});
