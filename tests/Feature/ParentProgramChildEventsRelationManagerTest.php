<?php

use App\Enums\EventStructure;
use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Filament\Ahli\Resources\Events\Pages\EditEvent as AhliEditEvent;
use App\Filament\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Resources\Events\RelationManagers\ChildEventsRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the child events relation manager for parent programs in the ahli panel', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $parentProgram = Event::factory()->for($institution)->create([
        'user_id' => $user->id,
        'submitter_id' => $user->id,
        'event_structure' => EventStructure::ParentProgram->value,
        'title' => 'Managed Parent Program',
    ]);

    $this->actingAs($user);

    expect(ChildEventsRelationManager::canViewForRecord($parentProgram, AhliEditEvent::class))->toBeTrue();
});

it('hides the child events relation manager for standalone events in the ahli panel', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $event = Event::factory()->for($institution)->create([
        'user_id' => $user->id,
        'submitter_id' => $user->id,
        'event_structure' => EventStructure::Standalone->value,
        'title' => 'Standalone Event',
    ]);

    $this->actingAs($user);

    expect(ChildEventsRelationManager::canViewForRecord($event, AhliEditEvent::class))->toBeFalse();
});

it('does not register recurrence or session relation managers anymore', function () {
    expect(AdminEventResource::getRelations())
        ->not->toContain('sessions')
        ->not->toContain('recurrence_rules');

    expect(AhliEventResource::getRelations())
        ->not->toContain('sessions')
        ->not->toContain('recurrence_rules');
});
