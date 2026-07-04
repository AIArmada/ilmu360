<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Filament\Ahli\Resources\Events\Pages\EditEvent as AhliEditEvent;
use App\Filament\Ahli\Resources\Events\Pages\ListEvents as AhliListEvents;
use App\Filament\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Resources\Events\Pages\ListEvents as AdminListEvents;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Authz\MemberRoleScopes;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignGlobalRoleForFeaturedGuard(User $user, string $role): void
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole($role);
}

function createAhliInstitutionAdmin(): array
{
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $speaker = Speaker::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addDays(2)->setTime(20, 0)->utc();
    $endsAt = now('Asia/Kuala_Lumpur')->addDays(2)->setTime(22, 0)->utc();

    $event = Event::factory()->for($institution)->create([
        'is_featured' => false,
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);
    OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($institution));

    app(EventKeyPersonSyncService::class)->sync($event, [$speaker->id], []);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    $scopedRole = Role::query()
        ->where('name', 'admin')
        ->where(app(PermissionRegistrar::class)->teamsKey, $scope->getKey())
        ->firstOrFail();

    Authz::withScope($scope, function () use ($user, $scopedRole): void {
        $user->syncRoles([$scopedRole]);
    }, $user);

    return [$user, $event];
}

/**
 * @return array<string, mixed>
 */
function featuredGuardEventEditPayload(Event $event): array
{
    return [
        'event_date' => $event->starts_at?->clone()->setTimezone('Asia/Kuala_Lumpur')->toDateString(),
        'prayer_time' => 'lain_waktu',
        'custom_time' => $event->starts_at?->clone()->setTimezone('Asia/Kuala_Lumpur')->format('H:i'),
        'end_time' => $event->ends_at?->clone()->setTimezone('Asia/Kuala_Lumpur')->format('H:i'),
        'event_format' => EventFormat::Physical->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'event_type' => [EventType::Other->value],
    ];
}

it('hides the featured field and column from ahli event surfaces', function () {
    [$member, $event] = createAhliInstitutionAdmin();
    Filament::setCurrentPanel('ahli');

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->assertFormFieldDoesNotExist('is_priority')
        ->assertFormFieldDoesNotExist('escalated_at')
        ->assertFormFieldDoesNotExist('is_featured');

    Livewire::actingAs($member)
        ->test(AhliListEvents::class)
        ->assertTableColumnHidden('is_featured');
});

it('keeps the featured field hidden on ahli surfaces even for application admins', function () {
    [$member, $event] = createAhliInstitutionAdmin();
    Filament::setCurrentPanel('ahli');

    assignGlobalRoleForFeaturedGuard($member, 'super_admin');

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->assertFormFieldDoesNotExist('is_priority')
        ->assertFormFieldDoesNotExist('escalated_at')
        ->assertFormFieldDoesNotExist('is_featured');

    Livewire::actingAs($member)
        ->test(AhliListEvents::class)
        ->assertTableColumnHidden('is_featured');
});

it('shows the featured field and column to application admins', function () {
    $administrator = User::factory()->create();
    assignGlobalRoleForFeaturedGuard($administrator, 'super_admin');
    Filament::setCurrentPanel('admin');

    $event = Event::factory()->create([
        'is_featured' => false,
    ]);

    Livewire::actingAs($administrator)
        ->test(AdminEditEvent::class, ['record' => $event->id])
        ->assertFormFieldExists('is_priority')
        ->assertFormFieldExists('escalated_at')
        ->assertFormFieldExists('is_featured');

    Livewire::actingAs($administrator)
        ->test(AdminListEvents::class)
        ->assertTableColumnExists('is_featured');
});

it('allows application admins to save events without mass assigning speakers', function () {
    $administrator = User::factory()->create();
    assignGlobalRoleForFeaturedGuard($administrator, 'super_admin');
    Filament::setCurrentPanel('admin');

    $speaker = Speaker::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addDays(2)->setTime(20, 0)->utc();
    $endsAt = now('Asia/Kuala_Lumpur')->addDays(2)->setTime(22, 0)->utc();

    $event = Event::factory()->create([
        'is_featured' => false,
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    app(EventKeyPersonSyncService::class)->sync($event, [$speaker->id], []);

    Livewire::actingAs($administrator)
        ->test(AdminEditEvent::class, ['record' => $event->id])
        ->fillForm([
            ...featuredGuardEventEditPayload($event),
            'speakers' => [$speaker->id],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->speakers->pluck('id')->all())->toBe([$speaker->id]);
});

it('ignores crafted ahli payloads that try to set featured on save', function () {
    [$member, $event] = createAhliInstitutionAdmin();
    Filament::setCurrentPanel('ahli');

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->fillForm(featuredGuardEventEditPayload($event))
        ->set('data.is_featured', true)
        ->set('data.escalated_at', now()->addDay()->toDateTimeString())
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->is_featured)->toBeFalse()
        ->and($event->fresh()->escalated_at)->toBeNull();
});

it('persists ahli registration settings without mass assigning the event model', function () {
    [$member, $event] = createAhliInstitutionAdmin();
    Filament::setCurrentPanel('ahli');

    $event->settings()->updateOrCreate(
        ['event_id' => $event->id],
        [
            'registration_required' => true,
            'registration_mode' => RegistrationMode::Event->value,
        ],
    );

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->assertFormSet([
            'registration_required' => true,
            'registration_mode' => RegistrationMode::Event->value,
        ])
        ->fillForm([
            'event_date' => $event->starts_at?->clone()->setTimezone('Asia/Kuala_Lumpur')->toDateString(),
            'prayer_time' => 'lain_waktu',
            'custom_time' => '20:00',
            'end_time' => '22:00',
            'event_format' => EventFormat::Physical->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'registration_required' => false,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $settings = $event->fresh()->settings;

    expect($settings)->not->toBeNull()
        ->and($settings?->registration_required)->toBeFalse()
        ->and($settings?->registration_mode)->toBe(RegistrationMode::Event);
});

it('persists ahli speaker updates through the shared event sync action', function () {
    [$member, $event] = createAhliInstitutionAdmin();
    $replacementSpeaker = Speaker::factory()->create();
    Filament::setCurrentPanel('ahli');

    Livewire::actingAs($member)
        ->test(AhliEditEvent::class, ['record' => $event->id])
        ->fillForm([
            ...featuredGuardEventEditPayload($event),
            'speakers' => [$replacementSpeaker->id],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->speakers->pluck('id')->all())->toBe([$replacementSpeaker->id]);
});
