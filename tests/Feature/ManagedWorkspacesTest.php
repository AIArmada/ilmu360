<?php

declare(strict_types=1);

use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Services\MembershipRoleSyncService;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Membership\TransferPersonOwnershipAction;
use App\Enums\ContributionSubjectType;
use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Livewire\Pages\Dashboard\InstitutionDashboard;
use App\Livewire\Pages\Dashboard\PersonDashboard;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Notifications\Membership\MemberInvitationNotification;
use App\Support\Api\Member\MemberResourceRegistry;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the institutions and speakers the user belongs to in the unified workspace', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Managed Institution']);
    $person = Person::factory()->create(['name' => 'Managed Speaker']);
    $outsideInstitution = Institution::factory()->create(['name' => 'Outside Institution']);
    $outsidePerson = Person::factory()->create(['name' => 'Outside Speaker']);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);
    $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);

    $this->actingAs($user)
        ->get(route('dashboard.organizations.index'))
        ->assertOk()
        ->assertSee('Managed Institution')
        ->assertSee('Managed Speaker')
        ->assertSee(route('dashboard.institutions', ['institution' => $institution->getKey()]), false)
        ->assertSee(route('dashboard.persons', $person), false)
        ->assertDontSee('Outside Institution')
        ->assertDontSee('Outside Speaker');

    expect($outsideInstitution->members()->whereKey($user->getKey())->exists())->toBeFalse()
        ->and($outsidePerson->members()->whereKey($user->getKey())->exists())->toBeFalse();
});

it('lets speaker admins manage members while keeping viewers read only', function (): void {
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Speaker Workspace Profile',
        'status' => 'verified',
    ]);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $viewer->id => ['role' => MemberRole::Viewer->value],
    ]);

    $editUrl = route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Speaker Workspace Profile')
        ->assertSee('Invite by email')
        ->assertSee('Edit speaker profile')
        ->assertSee($editUrl, false);

    $this->actingAs($viewer)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertDontSee('Invite by email')
        ->assertDontSee('Edit speaker profile');
});

it('shows speaker events and authorizes event editing through the speaker membership', function (): void {
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Speaker Event Workspace',
        'status' => 'verified',
    ]);
    $institution = Institution::factory()->create(['name' => 'Event Institution']);

    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $viewer->id => ['role' => MemberRole::Viewer->value],
    ]);

    $event = Event::factory()->create([
        'title' => 'Speaker Managed Event',
        'institution_id' => $institution->id,
        'status' => 'approved',
        'visibility' => 'private',
    ]);
    EventKeyPerson::query()->create([
        'event_id' => $event->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $draftEvent = Event::factory()->create([
        'title' => 'Speaker Draft Event',
        'institution_id' => $institution->id,
        'status' => 'draft',
        'visibility' => 'private',
    ]);
    EventKeyPerson::query()->create([
        'event_id' => $draftEvent->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $outsideEvent = Event::factory()->create(['title' => 'Outside Speaker Event']);
    $editUrl = EventResource::getUrl('edit', ['record' => $event], panel: 'ahli');
    $createUrl = route('dashboard.events.create-advanced', ['person' => $person->id]);

    expect(app(MemberPermissionGate::class)->canEventThroughPerson($admin, 'event.update', $event))->toBeTrue()
        ->and($admin->can('update', $event))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Manage speaker events')
        ->assertSee('Speaker Managed Event')
        ->assertSee('Speaker Draft Event')
        ->assertSee('Approved')
        ->assertSee($editUrl, false)
        ->assertSee($createUrl, false)
        ->assertDontSee('Outside Speaker Event');

    $this->actingAs($viewer);
    $memberResourceRegistry = app(MemberResourceRegistry::class);
    $eventResource = $memberResourceRegistry->resolve('events');
    $memberEventIds = $memberResourceRegistry->queryFor((string) $eventResource)->pluck('events.id')->all();

    expect($memberEventIds)
        ->toContain($event->id)
        ->toContain($draftEvent->id)
        ->not->toContain($outsideEvent->id);

    $this->actingAs($viewer)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Speaker Managed Event')
        ->assertSee('Speaker Draft Event')
        ->assertDontSee(route('events.show', $event), false)
        ->assertDontSee($editUrl, false)
        ->assertSee($createUrl, false);

    expect($admin->can('update', $event))->toBeTrue()
        ->and($viewer->can('update', $event))->toBeFalse()
        ->and($outsideEvent->persons()->whereKey($person->id)->exists())->toBeFalse();

    Livewire::withQueryParams(['event_status' => 'not-a-status'])
        ->actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person])
        ->assertSet('eventStatus', 'all');
});

it('counts a speaker event once when the speaker has multiple involvement rows', function (): void {
    $admin = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $person->members()->syncWithoutDetaching([$admin->id => ['role' => MemberRole::Admin->value]]);

    $event = Event::factory()->create(['status' => 'approved']);
    EventKeyPerson::query()->create([
        'event_id' => $event->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);
    EventKeyPerson::query()->create([
        'event_id' => $event->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 2,
        'visibility' => 'public',
    ]);

    $dashboard = Livewire::actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person]);

    expect($dashboard->instance()->events()->total())->toBe(1)
        ->and($dashboard->instance()->eventStats()['total'])->toBe(1);
});

it('carries the speaker workspace context into the event submission wizard', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Prefilled Speaker',
        'status' => 'verified',
    ]);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);

    Livewire::withQueryParams(['person' => $person->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class)
        ->assertSet('prefillPersonId', $person->id)
        ->assertSee('Prefilled Speaker')
        ->assertSee('Satu borang lengkap')
        ->assertSee('Pendaftaran &amp; tiket', false)
        ->assertSee('Jenis tiket')
        ->assertSee('Tempat duduk')
        ->assertSee('Mula dengan templat');

    Livewire::withQueryParams(['person' => $person->id])
        ->actingAs($user)
        ->test(Create::class)
        ->assertSet('prefillPersonId', $person->id)
        ->assertSet('data.persons', [$person->id]);
});

it('lets a speaker member create a paid managed event with a ticket quota', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Paid Speaker', 'status' => 'verified']);
    $institution = Institution::factory()->create(['name' => 'Paid Speaker Venue', 'status' => 'verified']);
    $category = eventCategoryId('lain_lain');

    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);
    $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);

    Livewire::withQueryParams(['person' => $person->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Speaker Ticketed Event')
        ->set('form.default_event_category_ids', [$category])
        ->set('form.primary_organizer_id', $person->id)
        ->set('form.location_institution_id', $institution->id)
        ->set('form.registration_required', true)
        ->set('form.pricing_mode', 'paid')
        ->set('form.tickets.0.price', '25.00')
        ->set('form.tickets.0.quota', '40')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Speaker Ticketed Event')->firstOrFail();
    $ticket = $event->primaryOccurrence?->ticketTypes()->first();

    expect($event->created_by_id)->toBe($user->id)
        ->and($event->pricing_mode->value)->toBe('paid')
        ->and($event->registration_mode->value)->toBe('required')
        ->and($event->primaryOccurrence?->capacity)->toBe(40)
        ->and($ticket)->toBeInstanceOf(TicketType::class)
        ->and($ticket?->price)->toBe(2500);
});

it('persists the selected ticket seating mode when seating is enabled', function (): void {
    $originalSeatingEnabled = config('events.features.commerce.ticket_seating_enabled');
    config()->set('events.features.commerce.ticket_seating_enabled', true);

    try {
        $user = User::factory()->create();
        $person = Person::factory()->create(['name' => 'Seated Speaker', 'status' => 'verified']);
        $institution = Institution::factory()->create(['name' => 'Seated Venue', 'status' => 'verified']);

        app(ScopedMemberRoleSeeder::class)->ensureForPerson();
        app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
        $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);
        $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);

        Livewire::withQueryParams(['person' => $person->id])
            ->actingAs($user)
            ->test(CreateAdvanced::class)
            ->set('form.title', 'Assigned Seating Event')
            ->set('form.default_event_category_ids', [eventCategoryId('lain_lain')])
            ->set('form.primary_organizer_id', $person->id)
            ->set('form.location_institution_id', $institution->id)
            ->set('form.registration_required', true)
            ->set('form.pricing_mode', 'paid')
            ->set('form.tickets.0.price', '25.00')
            ->set('form.tickets.0.quota', '40')
            ->set('form.tickets.0.seating_mode', SeatingMode::Assigned->value)
            ->set('form.seating.mode', SeatingMode::Assigned->value)
            ->set('form.seating.map_name', 'Main hall')
            ->set('form.seating.sections.0.capacity', '40')
            ->set('form.seating.sections.0.rows', '4')
            ->set('form.seating.sections.0.seats_per_row', '10')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $event = Event::query()->where('title', 'Assigned Seating Event')->firstOrFail();

        expect($event->primaryOccurrence?->ticketTypes()->first()?->seating_mode)->toBe(SeatingMode::Assigned);
    } finally {
        config()->set('events.features.commerce.ticket_seating_enabled', $originalSeatingEnabled);
    }
});

it('persists the public event profile while keeping institution context authoritative', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Context Institution', 'status' => 'verified']);
    $speaker = Person::factory()->create(['name' => 'Context Speaker', 'status' => 'verified']);
    $reference = Reference::factory()->create(['status' => 'verified']);
    $domain = submitEventTerm('domain');
    $discipline = submitEventTerm('discipline');
    $source = submitEventTerm('source');
    $issue = submitEventTerm('issue');

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);
    $speaker->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Complete Advanced Event')
        ->set('form.description', 'A complete event profile')
        ->set('form.default_event_category_ids', [eventCategoryId('lain_lain')])
        ->set('form.default_event_format', EventFormat::Physical->value)
        ->set('form.domain_tags', $domain->id)
        ->set('form.discipline_tags', [$discipline->id])
        ->set('form.source_tags', [$source->id])
        ->set('form.issue_tags', [$issue->id])
        ->set('form.references', [$reference->id])
        ->set('form.persons', [$speaker->id])
        ->set('form.event_date', now()->addDays(5)->toDateString())
        ->set('form.prayer_time', 'lain_waktu')
        ->set('form.custom_time', '20:30')
        ->set('form.end_time', '22:00')
        ->set('form.gender', 'all')
        ->set('form.age_group', ['adults'])
        ->set('form.children_allowed', false)
        ->set('form.languages', [languageId('ms')])
        ->set('form.primary_organizer_id', $speaker->id)
        ->set('form.location_institution_id', $speaker->id)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::query()->where('title', 'Complete Advanced Event')->firstOrFail();

    expect($event->institution_id)->toBe($institution->id)
        ->and($event->primaryOrganizerInvolvement?->involveable_id)->toBe($institution->id)
        ->and($event->references()->whereKey($reference->id)->exists())->toBeTrue()
        ->and($event->classifications()->where('event_term_id', $discipline->id)->exists())->toBeTrue()
        ->and($event->classifications()->where('event_term_id', $source->id)->exists())->toBeTrue()
        ->and($event->classifications()->where('event_term_id', $issue->id)->exists())->toBeTrue()
        ->and($event->languages->pluck('code')->all())->toContain('ms')
        ->and(EventKeyPerson::query()->where('event_id', $event->id)->where('involveable_id', $speaker->id)->exists())->toBeTrue()
        ->and(data_get($event->metadata, 'advanced_first_session.custom_time'))->toBe('20:30');

    Livewire::withQueryParams(['event' => $event->id])
        ->actingAs($user)
        ->test(Create::class)
        ->assertSet('data.title', 'Complete Advanced Event')
        ->assertSet('data.event_date', now()->addDays(5)->toDateString())
        ->assertSet('data.custom_time', '20:30')
        ->assertSet('data.references', [$reference->id])
        ->assertSet('data.persons', [$speaker->id]);
});

it('applies the public first-session timing rules to the advanced builder', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $domain = submitEventTerm('domain');

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Editor->value]]);

    $component = Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Timing Rules Event')
        ->set('form.default_event_category_ids', [eventCategoryId('lain_lain')])
        ->set('form.domain_tags', $domain->id)
        ->set('form.event_date', now()->addDays(5)->toDateString())
        ->set('form.prayer_time', 'sebelum_jumaat')
        ->call('submit')
        ->assertHasErrors('form.prayer_time');

    $component
        ->set('form.prayer_time', 'lain_waktu')
        ->set('form.custom_time', '20:00')
        ->set('form.end_time', '19:00')
        ->call('submit')
        ->assertHasErrors('form.end_time');

    $component
        ->set('form.title', 'Before Maghrib Outside Ramadan')
        ->set('form.program_starts_at', '2027-03-20T19:00')
        ->set('form.program_ends_at', '2027-03-20T22:00')
        ->set('form.event_date', '2027-03-20')
        ->set('form.prayer_time', 'sebelum_maghrib')
        ->set('form.end_time', '20:00')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Event::query()->where('title', 'Before Maghrib Outside Ramadan')->exists())->toBeTrue();
});

it('lets speaker admins invite, change, and remove non-owner members', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $member = User::factory()->create(['email' => 'speaker-member@example.test']);
    $person = Person::factory()->create(['name' => 'Speaker Member Management']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $roleSync = app(MembershipRoleSyncService::class);

    foreach (MemberRole::cases() as $role) {
        $roleSync->ensureExists($role, (string) $person->getKey(), $person::class, 'web');
    }

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $member->id => ['role' => MemberRole::Viewer->value],
    ]);

    Livewire::actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person])
        ->set('inviteEmail', 'new-speaker-member@example.test')
        ->set('inviteRole', MemberRole::Editor->value)
        ->call('invite')
        ->call('startEditingMember', $member->getKey())
        ->set('editingRole', MemberRole::Admin->value)
        ->call('saveMemberRole')
        ->call('removeMember', $member->getKey());

    expect($person->fresh()->members()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and(MemberInvitation::query()->where('email', 'new-speaker-member@example.test')->where('subject_id', $person->getKey())->exists())->toBeTrue();

    Notification::assertSentOnDemand(MemberInvitationNotification::class);
});

it('lets a person owner remove an admin while protecting the owner from admins', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Speaker Ownership Rules']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    addTestMember($person, $owner, MemberRole::Owner);
    addTestMember($person, $admin, MemberRole::Admin);

    Livewire::actingAs($owner)
        ->test(PersonDashboard::class, ['person' => $person])
        ->call('removeMember', $admin->getKey())
        ->assertHasNoErrors();

    expect($person->fresh()->members()->whereKey($admin->getKey())->exists())->toBeFalse()
        ->and($person->fresh()->members()->whereKey($owner->getKey())->exists())->toBeTrue();

    addTestMember($person, $admin, MemberRole::Admin);

    Livewire::actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person])
        ->call('removeMember', $owner->getKey())
        ->assertHasNoErrors()
        ->assertDispatched('app-toast');

    expect($person->fresh()->members()->whereKey($owner->getKey())->exists())->toBeTrue();

    expect(function () use ($person, $owner): void {
        app(RemoveMemberAction::class)->handle($person, $owner);
    })
        ->toThrow(AuthorizationException::class);
});

it('lets a person owner transfer ownership to an existing member', function (): void {
    $owner = User::factory()->create();
    $newOwner = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Speaker Ownership Transfer']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    addTestMember($person, $owner, MemberRole::Owner);
    addTestMember($person, $newOwner, MemberRole::Editor);

    $component = Livewire::actingAs($owner)
        ->test(PersonDashboard::class, ['person' => $person])
        ->assertSet('personId', $person->getKey());

    expect($person->members()->whereKey($owner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName())
        ->and(app(MemberPermissionGate::class)->canPerson($owner, 'person.transfer-ownership', $person))
        ->toBeTrue()
        ->and($component->instance()->canTransferOwnership())
        ->toBeTrue();

    $component
        ->assertSee(__('Make owner'))
        ->call('transferOwnership', $newOwner->getKey())
        ->assertHasNoErrors();

    $person->refresh();
    expect($person->members()->whereKey($owner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Admin->spatieRoleName())
        ->and($person->members()->whereKey($newOwner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName())
        ->and($person->ownerMember()->count())->toBe(1);
});

it('does not let a person admin transfer ownership', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $newOwner = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Speaker Transfer Authorization']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    addTestMember($person, $owner, MemberRole::Owner);
    addTestMember($person, $admin, MemberRole::Admin);
    addTestMember($person, $newOwner, MemberRole::Editor);

    expect(fn (): Person => app(TransferPersonOwnershipAction::class)->handle($person, $admin, $newOwner))
        ->toThrow(AuthorizationException::class);

    expect($person->fresh()->members()->whereKey($owner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName())
        ->and($person->fresh()->members()->whereKey($newOwner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Editor->spatieRoleName());
});

it('rejects a stale resolved membership mutation after ownership changes', function (): void {
    $owner = User::factory()->create();
    $newOwner = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Speaker Stale Membership']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    addTestMember($person, $owner, MemberRole::Owner);
    addTestMember($person, $newOwner, MemberRole::Editor);

    $staleMember = $person->members()->whereKey($newOwner->getKey())->firstOrFail();
    app(TransferPersonOwnershipAction::class)->handle($person, $owner, $newOwner);

    expect(function () use ($newOwner, $person, $staleMember): void {
        withGlobalOwnerContext(function () use ($newOwner, $person, $staleMember): void {
            app(AddMemberAction::class)->handleResolvedMember($person, $newOwner, MemberRole::Viewer, $staleMember);
        });
    })->toThrow(RuntimeException::class, 'Membership changed while this mutation was in progress. Please retry.');

    expect($person->fresh()->ownerMember()->count())->toBe(1)
        ->and($person->fresh()->members()->whereKey($newOwner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName());
});

it('does not invite an existing speaker member when email casing differs', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $member = User::factory()->create(['email' => 'Speaker.Member@example.test']);
    $person = Person::factory()->create();

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $member->id => ['role' => MemberRole::Viewer->value],
    ]);

    Livewire::actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person])
        ->set('inviteEmail', 'speaker.member@example.test')
        ->set('inviteRole', MemberRole::Editor->value)
        ->call('invite')
        ->assertHasErrors(['inviteEmail']);

    expect(MemberInvitation::query()
        ->where('subject_id', $person->getKey())
        ->where('email', 'speaker.member@example.test')
        ->exists())->toBeFalse();
});

it('does not let an admin of one institution manage another institution', function (): void {
    $user = User::factory()->create();
    $candidate = User::factory()->create(['email' => 'scoped-candidate@example.test']);
    $selectedInstitution = Institution::factory()->create(['name' => 'Selected Scope']);
    $managedInstitution = Institution::factory()->create(['name' => 'Managed Scope']);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $managedInstitution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);
    $selectedInstitution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Viewer->value]]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $selectedInstitution->getKey()]))
        ->assertOk()
        ->assertDontSee('Members & Roles')
        ->assertDontSee('Add Member');

    $component = Livewire::withQueryParams(['institution' => $selectedInstitution->getKey()])
        ->actingAs($user)
        ->test(InstitutionDashboard::class);

    expect($component->instance()->canManageMembers())->toBeFalse()
        ->and($selectedInstitution->fresh()->members()->whereKey($candidate->getKey())->exists())->toBeFalse();
});

it('forbids speaker workspace access to non-members', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.persons', $person))
        ->assertForbidden();
});
