<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Authz\InterimMembershipAuthorization;
use App\Support\Authz\InterimMembershipDirectory;

it('binds the membership seams to the interim pivot adapters', function (): void {
    expect(app(MembershipDirectory::class))->toBeInstanceOf(InterimMembershipDirectory::class)
        ->and(app(MembershipAuthorization::class))->toBeInstanceOf(InterimMembershipAuthorization::class);
});

it('reads subjects, roles and members from the current member pivots', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $otherInstitution = Institution::factory()->create();

    addTestMember($institution, $user, MemberRole::Admin);

    $directory = app(MembershipDirectory::class);
    $accountId = (string) $user->getKey();
    $institutionId = (string) $institution->getKey();

    $memberships = $directory->membershipsFor($accountId);
    $members = $directory->members(MembershipSubjectType::Institution, $institutionId);

    expect($directory->subjectIdsFor($accountId, MembershipSubjectType::Institution))
        ->toBe([$institutionId])
        ->and($directory->subjectIdsFor($accountId, MembershipSubjectType::Institution, 'owner'))
        ->toBe([])
        ->and($directory->hasRole($accountId, MembershipSubjectType::Institution, $institutionId, 'admin'))
        ->toBeTrue()
        ->and($directory->hasRole($accountId, MembershipSubjectType::Institution, (string) $otherInstitution->getKey(), 'admin'))
        ->toBeFalse()
        ->and($directory->hasAnyRole($accountId, MembershipSubjectType::Institution, $institutionId, ['owner', 'admin']))
        ->toBeTrue()
        ->and($memberships)->toHaveCount(1)
        ->and($memberships[0]->accountId)->toBe($accountId)
        ->and($memberships[0]->subjectId)->toBe($institutionId)
        ->and($members)->toHaveCount(1)
        ->and($members[0]->role)->toBe('admin')
        ->and($directory->membershipsFor($accountId, MembershipSubjectType::Person))->toBe([]);
});

it('authorizes through the current member permission rules', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    addTestMember($institution, $user, MemberRole::Admin);

    $authorization = app(MembershipAuthorization::class);
    $accountId = (string) $user->getKey();
    $institutionId = (string) $institution->getKey();

    expect($authorization->can($accountId, 'institution.update', MembershipSubjectType::Institution, $institutionId))
        ->toBeTrue()
        ->and($authorization->can($accountId, 'institution.delete', MembershipSubjectType::Institution, $institutionId))
        ->toBeFalse()
        ->and($authorization->hasAny($accountId, 'institution.update', MembershipSubjectType::Institution))
        ->toBeTrue()
        ->and($authorization->hasAny($accountId, 'unknown.permission', MembershipSubjectType::Institution))
        ->toBeFalse()
        ->and($authorization->membersWithPermission(MembershipSubjectType::Institution, $institutionId, 'institution.update'))
        ->toContain($accountId)
        ->and($authorization->membersWithPermission(MembershipSubjectType::Institution, $institutionId, 'institution.delete'))
        ->toBe([]);
});

it('authorizes event access through person membership', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
        'visibility' => 'private',
    ]);

    addTestMember($person, $user, MemberRole::Admin);

    EventKeyPerson::query()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $authorization = app(MembershipAuthorization::class);

    expect($authorization->canThroughPerson((string) $user->getKey(), 'event.delete', (string) $event->getKey()))
        ->toBeTrue()
        ->and($authorization->canThroughPerson((string) $user->getKey(), 'unknown.permission', (string) $event->getKey()))
        ->toBeFalse();
});
