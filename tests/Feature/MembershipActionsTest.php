<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Actions\Membership\AddMemberToSubject;
use App\Actions\Membership\AssignOwnerToNewSubject;
use App\Actions\Membership\ChangeSubjectMemberRole;
use App\Actions\Membership\RemoveMemberFromSubject;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('resolves shared scoped role ids from role names', function () {
    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $resolvedRoleId = app(MemberRoleCatalog::class)->resolveRoleId(MemberSubjectType::Institution, 'owner');
    $roleOptions = app(MemberRoleCatalog::class)->roleOptionsFor(MemberSubjectType::Institution);

    expect($resolvedRoleId)->toBeString()
        ->and($resolvedRoleId)->toBe(array_search('owner', $roleOptions, true));
});

it('adds an institution member and assigns exactly one shared scoped role', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');

    expect($institution->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member, MemberSubjectType::Institution))->toBe(['owner']);
});

it('changes a member role by replacing the prior shared scoped role', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'viewer');
    app(ChangeSubjectMemberRole::class)->handle($institution, $member, 'admin');

    expect(app(MemberRoleCatalog::class)->roleNamesFor($member, MemberSubjectType::Institution))->toBe(['admin']);
});

it('does not clear an existing shared scoped role when attaching another membership without an explicit role', function () {
    $institution = Institution::factory()->create();
    $anotherInstitution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');
    app(AddMemberToSubject::class)->handle($anotherInstitution, $member);

    expect($anotherInstitution->fresh()->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member, MemberSubjectType::Institution))->toBe(['owner']);
});

it('removes a member and clears a non-protected shared scoped role assignment when no memberships remain', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'viewer');
    app(RemoveMemberFromSubject::class)->handle($institution, $member);

    expect($institution->fresh()->members()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member, MemberSubjectType::Institution))->toBe([]);
});

it('preserves the shared scoped role assignment while other memberships of the same type remain', function () {
    $institution = Institution::factory()->create();
    $anotherInstitution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'viewer');
    app(AddMemberToSubject::class)->handle($anotherInstitution, $member, 'viewer');
    app(RemoveMemberFromSubject::class)->handle($institution, $member);

    expect($institution->fresh()->members()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and($anotherInstitution->fresh()->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member->fresh(), MemberSubjectType::Institution))->toBe(['viewer']);
});

it('does not allow removing protected ownership memberships from local membership actions', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');

    expect(fn () => app(RemoveMemberFromSubject::class)->handle($institution, $member))
        ->toThrow(RuntimeException::class, 'Protected ownership roles can only be changed from the global authz surface.');

    expect($institution->fresh()->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member->fresh(), MemberSubjectType::Institution))->toBe(['owner']);
});

it('does not allow changing protected owner roles from local membership actions', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');

    expect(fn () => app(ChangeSubjectMemberRole::class)->handle($institution, $member, 'admin'))
        ->toThrow(RuntimeException::class, 'Protected ownership roles can only be changed from the global authz surface.');
});

it('allows changing protected owner roles when the central authz override is explicitly used', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');
    app(ChangeSubjectMemberRole::class)->handle(MemberSubjectType::Institution, $member, 'admin', allowProtectedRoleChange: true);

    expect(app(MemberRoleCatalog::class)->roleNamesFor($member->fresh(), MemberSubjectType::Institution))->toBe(['admin']);
});

it('assigns the primary role when ownership is delegated to a new event subject', function () {
    $event = Event::factory()->create();
    $member = User::factory()->create();

    app(AssignOwnerToNewSubject::class)->handle($event, $member);

    expect($event->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and($event->members()->whereKey($member->getKey())->whereNotNull('event_user.joined_at')->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($member, MemberSubjectType::Event))->toBe(['organizer']);
});

it('does not create model specific authz scopes when institutions and speakers are created', function () {
    Institution::factory()->create();
    Speaker::factory()->create();

    expect(DB::table('authz_scopes')
        ->whereIn('scopeable_type', [Institution::class, Speaker::class])
        ->count())->toBe(0);
});

it('stores membership role pivots in the shared member scope instead of a model specific scope', function () {
    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'owner');

    $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
    $teamsKey = app(PermissionRegistrar::class)->teamsKey;
    $modelMorphKey = config('permission.column_names.model_morph_key', 'model_id');
    $rolePivotKey = app(PermissionRegistrar::class)->pivotRole;
    $sharedScopeId = app(MemberRoleScopes::class)->institution()->getKey();

    $pivotRows = DB::table($modelHasRolesTable)
        ->where($modelMorphKey, $member->getKey())
        ->get([$rolePivotKey, $teamsKey]);

    expect($pivotRows)->toHaveCount(1)
        ->and((string) $pivotRows->first()->{$teamsKey})->toBe((string) $sharedScopeId);

    $roleNames = Authz::withScope(app(MemberRoleScopes::class)->institution(), fn (): array => $member->getRoleNames()->values()->all(), $member);

    expect($roleNames)->toBe(['owner'])
        ->and(DB::table('authz_scopes')
            ->where('scopeable_type', Institution::class)
            ->count())->toBe(0);
});
