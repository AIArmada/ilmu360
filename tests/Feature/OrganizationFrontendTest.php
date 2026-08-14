<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Seating\Models\Seat;
use AIArmada\Seating\Models\SeatMap;
use AIArmada\Ticketing\Models\TicketType;
use App\Livewire\Pages\Dashboard\Organizations\CreateEvent;
use App\Livewire\Pages\Dashboard\Organizations\CreateOrganization;
use App\Livewire\Pages\Dashboard\Organizations\Workspace;
use App\Livewire\Pages\Membership\ShowInvitation;
use App\Models\Event;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Notifications\Membership\MemberInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('shows the organization creation entry to users without an organization', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard.organizations.create'), false);
});

it('lets a signed-in person create an organization from the frontend', function (): void {
    $owner = User::factory()->create();

    Livewire::actingAs($owner)
        ->test(CreateOrganization::class)
        ->set('name', 'Ilmu Organizers')
        ->set('description', 'A community event team.')
        ->call('submit')
        ->assertRedirect(route('dashboard.organizations.show', Organization::query()->where('name', 'Ilmu Organizers')->firstOrFail()));

    $organization = Organization::query()->where('name', 'Ilmu Organizers')->firstOrFail();

    expect($organization->members()->whereKey($owner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName());

    $this->actingAs($owner)
        ->get(route('dashboard.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Ilmu Organizers');
});

it('blocks non-members from an organization workspace', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Private Workspace']);

    $this->actingAs($outsider)
        ->get(route('dashboard.organizations.show', $organization))
        ->assertForbidden();
});

it('lets organization managers invite members and change non-owner roles', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Member Workspace']);
    addTestMember($organization, $member, MemberRole::Viewer);

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->set('inviteEmail', 'new-member@example.com')
        ->set('inviteRole', MemberRole::Editor->value)
        ->call('invite')
        ->call('startEditingMember', $member->getKey())
        ->set('editingRole', MemberRole::Admin->value)
        ->call('saveMemberRole');

    expect($organization->members()->whereKey($member->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Admin->spatieRoleName())
        ->and(MemberInvitation::query()->where('email', 'new-member@example.com')->where('subject_id', $organization->getKey())->exists())
        ->toBeTrue();

    Notification::assertSentOnDemand(MemberInvitationNotification::class);
});

it('protects ownership transfer and removes the old owner role safely', function (): void {
    $owner = User::factory()->create();
    $newOwner = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Ownership Workspace']);
    addTestMember($organization, $newOwner, MemberRole::Editor);

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->call('transferOwnership', $newOwner->getKey());

    $organization->refresh();
    expect($organization->members()->whereKey($owner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Admin->spatieRoleName())
        ->and($organization->members()->whereKey($newOwner->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName());
});

it('lets an invited organization member accept a frontend invitation', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Invitation Workspace']);

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'organization',
        'subject_id' => $organization->getKey(),
        'email' => $invitee->email,
        'role' => MemberRole::Viewer->spatieRoleName(),
        'token' => 'organization-invitation-token',
        'invited_by' => $owner->getKey(),
    ]);

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, ['token' => $invitation->token])
        ->call('accept')
        ->assertRedirect(route('dashboard.organizations.show', $organization));

    expect($organization->members()->whereKey($invitee->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Viewer->spatieRoleName());
});

it('creates a free organization event with a ticket type', function (): void {
    $owner = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Free Events']);
    $category = eventCategoryId('lain_lain');

    Livewire::actingAs($owner)
        ->test(CreateEvent::class, ['organization' => $organization])
        ->set('form.title', 'Free Community Class')
        ->set('form.event_category_ids', [$category])
        ->set('form.pricing_mode', 'free')
        ->set('form.tickets.0.quota', '50')
        ->call('submit')
        ->assertRedirect(route('dashboard.organizations.show', $organization));

    OwnerContext::withOwner($organization, function () use ($organization): void {
        $event = Event::query()->where('owner_id', $organization->getKey())->firstOrFail();
        expect($event->pricing_mode->value)->toBe('free')
            ->and($event->primaryOccurrence?->ticketTypes()->first()?->price)->toBe(0);
    });
});

it('creates paid assigned-seat ticketing with an owner-scoped seat map', function (): void {
    $owner = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Paid Events']);
    $category = eventCategoryId('lain_lain');

    Livewire::actingAs($owner)
        ->test(CreateEvent::class, ['organization' => $organization])
        ->set('form.title', 'Paid Conference')
        ->set('form.event_category_ids', [$category])
        ->set('form.pricing_mode', 'paid')
        ->set('form.tickets.0.price', '25.00')
        ->set('form.tickets.0.quota', '20')
        ->set('form.tickets.0.seating_mode', 'assigned')
        ->set('form.seating.mode', 'assigned')
        ->set('form.seating.sections.0.capacity', '20')
        ->set('form.seating.sections.0.rows', '2')
        ->set('form.seating.sections.0.seats_per_row', '10')
        ->call('submit')
        ->assertRedirect(route('dashboard.organizations.show', $organization));

    OwnerContext::withOwner($organization, function () use ($organization): void {
        $event = Event::query()->where('owner_id', $organization->getKey())->firstOrFail();
        $ticket = $event->primaryOccurrence?->ticketTypes()->first();
        $map = SeatMap::forHost($event)->first();

        expect($event->pricing_mode->value)->toBe('paid')
            ->and($ticket)->toBeInstanceOf(TicketType::class)
            ->and($ticket?->price)->toBe(2500)
            ->and($map)->toBeInstanceOf(SeatMap::class)
            ->and($map?->sections()->first()?->capacity)->toBe(20)
            ->and($map?->sections()->first()?->seats()->count())->toBe(20)
            ->and(Seat::query()->count())->toBe(20);
    });
});
