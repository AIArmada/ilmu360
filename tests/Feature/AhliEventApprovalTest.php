<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Filament\Ahli\Resources\Events\Pages\EditEvent as AhliEditEvent;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();
});

function assignInstitutionRole(User $user, string $role): void
{
    $scope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($scope, function () use ($user, $role): void {
        $user->syncRoles([$role]);
    }, $user);
}

function assignSpeakerRole(User $user, string $role): void
{
    $scope = app(MemberRoleScopes::class)->speaker();

    Authz::withScope($scope, function () use ($user, $role): void {
        $user->syncRoles([$role]);
    }, $user);
}

it('allows institution admins to approve pending public-submitted events from the ahli edit page', function () {
    $approver = User::factory()->create();
    $submitter = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved by institution admin'])
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and($event->published_at)->not->toBeNull();

    $review = ModerationReview::query()->where('event_id', $event->id)->latest()->first();

    expect($review)->not->toBeNull()
        ->and($review?->decision)->toBe('approved')
        ->and($review?->moderator_id)->toBe($approver->id);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $submitter->id,
        'trigger' => 'submission_approved',
    ]);
});

it('allows institution admins to submit draft public-submitted events for review from the ahli edit page', function () {
    $approver = User::factory()->create();
    $submitter = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'draft',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('submit_for_review')
        ->callAction('submit_for_review')
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('pending')
        ->and($event->published_at)->toBeNull();
});

it('allows speaker admins to approve pending public-submitted speaker-organized events from the ahli edit page', function () {
    $approver = User::factory()->create();
    $submitter = User::factory()->create();
    $speaker = Speaker::factory()->create();

    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Speaker::class,
        'organizer_id' => $speaker->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $speaker->members()->syncWithoutDetaching([$approver->id]);
    assignSpeakerRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved by speaker admin'])
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and($event->published_at)->not->toBeNull();

    $review = ModerationReview::query()->where('event_id', $event->id)->latest()->first();

    expect($review)->not->toBeNull()
        ->and($review?->decision)->toBe('approved')
        ->and($review?->moderator_id)->toBe($approver->id);

    $this->assertDatabaseHas('notification_messages', [
        'user_id' => $submitter->id,
        'trigger' => 'submission_approved',
    ]);
});

it('allows speaker editors to approve pending public-submitted speaker-organized events from the ahli edit page', function () {
    $editor = User::factory()->create();
    $submitter = User::factory()->create();
    $speaker = Speaker::factory()->create();

    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Speaker::class,
        'organizer_id' => $speaker->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $speaker->members()->syncWithoutDetaching([$editor->id]);
    assignSpeakerRole($editor, 'editor');

    $this->actingAs($editor);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved by speaker editor'])
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and($event->published_at)->not->toBeNull();
});

it('allows institution admins to approve pending speaker-organized public submissions linked to their institution', function () {
    $approver = User::factory()->create();
    $submitter = User::factory()->create();
    $institution = Institution::factory()->create();
    $speaker = Speaker::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Speaker::class,
        'organizer_id' => $speaker->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved by institution admin via linked speaker event'])
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and($event->published_at)->not->toBeNull();
});

it('allows institution editors to approve pending public-submitted events from the ahli edit page', function () {
    $editor = User::factory()->create();
    $submitter = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => $submitter->id,
    ]);

    EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

    $institution->members()->syncWithoutDetaching([$editor->id]);
    assignInstitutionRole($editor, 'editor');

    $this->actingAs($editor);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved by institution editor'])
        ->assertNotified();

    $event->refresh();

    expect((string) $event->status)->toBe('approved')
        ->and($event->published_at)->not->toBeNull();
});

it('hides the ahli approve action for pending events that did not come from the public submission flow', function () {
    $approver = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => null,
    ]);

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionHidden('approve');
});

it('hides the ahli submit for review action for draft events that did not come from the public submission flow', function () {
    $approver = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'status' => 'draft',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => null,
    ]);

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $event->id])
        ->assertActionHidden('submit_for_review');
});

it('propagates approve actions from a parent program to its child events', function () {
    $approver = User::factory()->create();
    $submitter = User::factory()->create();
    $institution = Institution::factory()->create();

    $parentEvent = Event::factory()->parentProgram()->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    Event::factory()->childEvent($parentEvent)->for($institution)->create([
        'status' => 'pending',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'submitter_id' => $submitter->id,
        'published_at' => null,
    ]);

    EventSubmission::factory()->for($parentEvent)->for($submitter, 'submitter')->create();

    $institution->members()->syncWithoutDetaching([$approver->id]);
    assignInstitutionRole($approver, 'admin');

    $this->actingAs($approver);

    Livewire::test(AhliEditEvent::class, ['record' => $parentEvent->id])
        ->assertActionVisible('approve')
        ->callAction('approve', ['note' => 'Approved parent program'])
        ->assertNotified();

    $parentEvent->refresh();
    $childStatuses = $parentEvent->childEvents()->get()->map(fn (Event $childEvent): string => (string) $childEvent->status)->all();

    expect((string) $parentEvent->status)->toBe('approved')
        ->and($childStatuses)->toBe(['approved']);
});
