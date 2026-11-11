<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Bookmark;
use App\Shared\Events\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\User;
use Livewire\Livewire;

it('saves and unsaves an event from the institution page majlis card', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.institutions.show', ['institution' => $institution])
        ->assertSee($event->title)
        ->assertSee('data-save-state="unsaved"', false)
        ->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="saved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeTrue();

    $component->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="unsaved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeFalse();
});

it('saves and unsaves an event from the reference page majlis card', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $event->references()->attach($reference->id);

    $component = Livewire::actingAs($user)
        ->test('pages.references.show', ['reference' => $reference])
        ->assertSee($event->title)
        ->assertSee('data-save-state="unsaved"', false)
        ->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="saved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeTrue();

    $component->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="unsaved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeFalse();
});

it('redirects guests to login when saving from the institution page majlis card', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    Livewire::test('pages.institutions.show', ['institution' => $institution])
        ->call('toggleSave', $event->getKey())
        ->assertRedirect(route('login', ['redirect' => route('institutions.show', $institution, false)]));
});

it('redirects guests to login when saving from the reference page majlis card', function () {
    $reference = Reference::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $event->references()->attach($reference->id);

    Livewire::test('pages.references.show', ['reference' => $reference])
        ->call('toggleSave', $event->getKey())
        ->assertRedirect(route('login', ['redirect' => route('references.show', $reference, false)]));
});
