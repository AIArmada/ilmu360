<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\MembersRelationManager as InstitutionMembersRelationManager;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Submission\PublicSubmissionUiEvents;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Filament\Forms\Components\RichEditor;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignGlobalRole(User $user, string $role): void
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole($role);
}

function normalizeInstitutionContactsForAdminForm(Institution $institution): void
{
    $institution->contacts()
        ->where('category', 'phone')
        ->update(['value' => '+60112223344']);
}

it('uses a rich editor for institution description on the admin edit form', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $institution = Institution::factory()->create([
        'description' => '<p>Existing description</p>',
    ]);

    Livewire::actingAs($admin)
        ->test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldExists('description', fn (RichEditor $editor): bool => true);
});

it('disables turning off institution public submission when credible precondition fails', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $member = User::factory()->create([
        'phone' => null,
        'phone_verified_at' => null,
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['owner']);
    }, $member);

    $this->actingAs($admin);

    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldDisabled('allow_public_event_submission');

    expect($institution->fresh()->allow_public_event_submission)->toBeTrue();
});

it('refreshes institution public submission toggle eligibility without remounting the edit page', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $this->actingAs($admin);

    $page = Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldDisabled('allow_public_event_submission');

    $member = User::factory()->create([
        'phone' => '+60115550000',
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['owner']);
    }, $member);

    $page->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE)
        ->assertFormFieldEnabled('allow_public_event_submission');
});

it('dispatches an institution toggle refresh event after adding an eligible member', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $member = User::factory()->create([
        'phone' => '+60119998888',
        'phone_verified_at' => Carbon::now(),
    ]);

    $scope = app(MemberRoleScopes::class)->institution();
    $ownerRoleId = Authz::withScope($scope, fn (): string => (string) Role::findByName('owner', 'web')->getKey());

    Livewire::actingAs($admin)
        ->test(InstitutionMembersRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->callTableAction('addMember', data: [
            'user_id' => $member->id,
            'role_id' => $ownerRoleId,
        ])
        ->assertDispatchedTo(EditInstitution::class, PublicSubmissionUiEvents::REFRESH_TOGGLE);
});

it('keeps institution submission public until the toggle is explicitly turned off', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'moderator');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['admin']);
    }, $member);

    $this->actingAs($admin);

    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldEnabled('allow_public_event_submission')
        ->assertFormSet([
            'allow_public_event_submission' => true,
        ]);

    expect($institution->fresh()->allow_public_event_submission)->toBeTrue();
});

it('locks institution submission through the toggle and stores lock metadata', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'admin');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['owner']);
    }, $member);

    $this->actingAs($admin);

    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->fillForm([
            'allow_public_event_submission' => false,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $institution->refresh();

    expect($institution->allow_public_event_submission)->toBeFalse()
        ->and($institution->public_submission_locked_by)->toBe($admin->id)
        ->and($institution->public_submission_locked_at)->not->toBeNull();
});

it('saves institution contact phone values on the edit page without nulling the database value', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'admin');

    $institution = Institution::factory()->create();
    $institution->contacts()->create([
        'category' => ContactCategory::Phone->value,
        'type' => ContactType::Main->value,
        'value' => '+60112223344',
        'is_public' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($institution->fresh()->contacts()->where('category', ContactCategory::Phone->value)->value('value'))
        ->not->toBeNull()
        ->not->toBeEmpty();
});

it('only enables the public submission toggle for global admin, admin, and moderator', function () {
    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);

    $member = User::factory()->create([
        'phone' => '+60117778899',
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['owner']);
    }, $member);

    $superAdmin = User::factory()->create();
    assignGlobalRole($superAdmin, 'super_admin');

    $admin = User::factory()->create();
    assignGlobalRole($admin, 'admin');

    $moderator = User::factory()->create();
    assignGlobalRole($moderator, 'moderator');

    $this->actingAs($superAdmin);
    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldEnabled('allow_public_event_submission');

    $this->actingAs($admin);
    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldEnabled('allow_public_event_submission');

    $this->actingAs($moderator);
    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->assertFormFieldEnabled('allow_public_event_submission');
});

it('auto-reopens institution submission when lock credibility drifts', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $institution = Institution::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    normalizeInstitutionContactsForAdminForm($institution);

    $member = User::factory()->create([
        'phone' => '+60119990000',
        'phone_verified_at' => Carbon::now(),
    ]);

    $institution->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->institution();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['owner']);
    }, $member);

    $this->actingAs($admin);

    Livewire::test(EditInstitution::class, ['record' => $institution->id])
        ->fillForm([
            'allow_public_event_submission' => false,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $institution->refresh();
    expect($institution->allow_public_event_submission)->toBeFalse();

    $member->update([
        'phone_verified_at' => null,
    ]);

    $institution->refresh();
    expect($institution->allow_public_event_submission)->toBeTrue();
});

it('supports locking and unlocking speaker records through the toggle', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $speaker = Speaker::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    $speaker->address()->update([
        'country_id' => 132,
    ]);

    $member = User::factory()->create([
        'phone' => '+60112220000',
        'phone_verified_at' => Carbon::now(),
    ]);

    $speaker->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->speaker();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['admin']);
    }, $member);

    $this->actingAs($admin);

    Livewire::test(EditSpeaker::class, ['record' => $speaker->id])
        ->fillForm([
            'allow_public_event_submission' => false,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $speaker->refresh();
    expect($speaker->allow_public_event_submission)->toBeFalse();

    Livewire::test(EditSpeaker::class, ['record' => $speaker->id])
        ->fillForm([
            'allow_public_event_submission' => true,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($speaker->fresh()->allow_public_event_submission)->toBeTrue();
});

it('refreshes speaker public submission toggle eligibility without remounting the edit page', function () {
    $admin = User::factory()->create();
    assignGlobalRole($admin, 'super_admin');

    $speaker = Speaker::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    $speaker->address()->update([
        'country_id' => 132,
    ]);

    $this->actingAs($admin);

    $page = Livewire::test(EditSpeaker::class, ['record' => $speaker->id])
        ->assertFormFieldDisabled('allow_public_event_submission');

    $member = User::factory()->create([
        'phone' => '+60116660000',
        'phone_verified_at' => Carbon::now(),
    ]);

    $speaker->members()->syncWithoutDetaching([$member->id]);

    $scope = app(MemberRoleScopes::class)->speaker();
    Authz::withScope($scope, function () use ($member): void {
        $member->syncRoles(['admin']);
    }, $member);

    $page->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE)
        ->assertFormFieldEnabled('allow_public_event_submission');
});
