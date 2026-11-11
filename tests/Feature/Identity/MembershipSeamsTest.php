<?php

use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use App\Enums\EventKeyPersonRole;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\FeatureServiceProvider as MembershipFeatureServiceProvider;
use App\Features\Membership\Services\MembershipApplicationsService;
use App\Features\Membership\Services\MembershipAuthorizationService;
use App\Features\Membership\Services\MembershipCommandsService;
use App\Features\Membership\Services\MembershipDirectoryService;
use App\Features\Membership\Services\MembershipInvitationsService;
use App\Features\Organizations\FeatureServiceProvider as OrganizationsFeatureServiceProvider;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectCatalog;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Membership\EloquentMembershipSubjectCatalog;

it('binds the membership seams to feature services and the subject catalog to its root adapter', function (): void {
    expect(app(MembershipDirectory::class))->toBeInstanceOf(MembershipDirectoryService::class)
        ->and(app(MembershipAuthorization::class))->toBeInstanceOf(MembershipAuthorizationService::class)
        ->and(app(MembershipCommands::class))->toBeInstanceOf(MembershipCommandsService::class)
        ->and(app(MembershipApplications::class))->toBeInstanceOf(MembershipApplicationsService::class)
        ->and(app(MembershipInvitations::class))->toBeInstanceOf(MembershipInvitationsService::class)
        ->and(app(MembershipSubjectCatalog::class))->toBeInstanceOf(EloquentMembershipSubjectCatalog::class);
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

it('registers the membership and organizations feature providers exactly once', function (): void {
    $providers = require base_path('bootstrap/providers.php');
    $loaded = app()->getLoadedProviders();

    expect(array_keys($providers, MembershipFeatureServiceProvider::class, true))->toHaveCount(1)
        ->and(array_keys($providers, OrganizationsFeatureServiceProvider::class, true))->toHaveCount(1)
        ->and($loaded)->toHaveKey(MembershipFeatureServiceProvider::class)
        ->and($loaded)->toHaveKey(OrganizationsFeatureServiceProvider::class);
});

it('resolves membership subjects through directory, organization and event boundaries', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Katalog']);
    $person = Person::factory()->create(['name' => 'Ahmad Katalog']);
    $reference = Reference::factory()->create([
        'title' => 'Kitab Katalog',
        'slug' => 'kitab-katalog',
        'status' => 'verified',
    ]);
    $event = Event::factory()->create([
        'title' => 'Majlis Katalog',
        'slug' => 'majlis-katalog',
    ]);
    $organization = CreateOrganizationAction::make()->handle($user, ['name' => 'Organisasi Katalog']);

    addTestMember($institution, $user, MemberRole::Viewer);
    addTestMember($person, $user, MemberRole::Viewer);
    addTestMember($reference, $user, MemberRole::Viewer);
    addTestMember($event, $user, MemberRole::Viewer);

    $catalog = app(MembershipSubjectCatalog::class);
    $accountId = (string) $user->getKey();

    $institutionSubject = $catalog->find(MembershipSubjectType::Institution, (string) $institution->getKey());
    $personSubject = $catalog->find(MembershipSubjectType::Person, (string) $person->getKey());
    $referenceSubject = $catalog->find(MembershipSubjectType::Reference, (string) $reference->getKey());
    $eventSubject = $catalog->find(MembershipSubjectType::Event, (string) $event->getKey());
    $organizationSubject = $catalog->find(MembershipSubjectType::Organization, (string) $organization->getKey());

    expect($institutionSubject?->label)->toBe('Masjid Katalog')
        ->and($institutionSubject?->publicUrl)->toBe(route('institutions.show', $institution))
        ->and($personSubject?->label)->toBe($person->formatted_name)
        ->and($referenceSubject?->label)->toBe($reference->displayTitle())
        ->and($eventSubject?->label)->toBe('Majlis Katalog')
        ->and($eventSubject?->publicUrl)->toBe(route('events.show', ['event' => 'majlis-katalog']))
        ->and($organizationSubject?->label)->toBe('Organisasi Katalog')
        ->and($catalog->exists(MembershipSubjectType::Institution, (string) $institution->getKey()))->toBeTrue()
        ->and($catalog->find(MembershipSubjectType::Institution, 'missing-subject'))->toBeNull()
        ->and(array_map(static fn ($subject): string => $subject->id, $catalog->forAccount($accountId, MembershipSubjectType::Institution)))
        ->toBe([(string) $institution->getKey()])
        ->and(array_map(static fn ($subject): string => $subject->id, $catalog->forAccount($accountId, MembershipSubjectType::Organization)))
        ->toBe([(string) $organization->getKey()]);

    $catalogSource = file_get_contents(base_path('app/Support/Membership/EloquentMembershipSubjectCatalog.php'));

    expect($catalogSource)->toBeString()
        ->and($catalogSource)->not->toContain('App\\Models\\');
});

it('writes membership pivots through the command boundary', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $commands = app(MembershipCommands::class);
    $directory = app(MembershipDirectory::class);

    $accountId = (string) $user->getKey();
    $institutionId = (string) $institution->getKey();

    $commands->addMember(MembershipSubjectType::Institution, $institutionId, $accountId, 'viewer');

    expect($directory->hasRole($accountId, MembershipSubjectType::Institution, $institutionId, 'viewer'))->toBeTrue();

    $commands->changeRole(MembershipSubjectType::Institution, $institutionId, $accountId, 'editor');

    expect($directory->hasRole($accountId, MembershipSubjectType::Institution, $institutionId, 'editor'))->toBeTrue()
        ->and($directory->hasRole($accountId, MembershipSubjectType::Institution, $institutionId, 'viewer'))->toBeFalse();

    $commands->removeMember(MembershipSubjectType::Institution, $institutionId, $accountId);

    expect($directory->hasRole($accountId, MembershipSubjectType::Institution, $institutionId, 'editor'))->toBeFalse();
});

it('reads applications and invitations through the feature query boundaries', function (): void {
    $applicant = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create();

    $application = MembershipApplication::factory()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'applicant_id' => $applicant->getKey(),
        'reviewer_id' => $reviewer->getKey(),
    ]);

    $applications = app(MembershipApplications::class);
    $applicantId = (string) $applicant->getKey();
    $applicationId = (string) $application->getKey();

    expect($applications->forApplicant($applicantId)[0]->id ?? null)->toBe($applicationId)
        ->and($applications->findForApplicant($applicantId, $applicationId)?->application->id)->toBe($applicationId)
        ->and($applications->find($applicationId)?->reviewerId)->toBe((string) $reviewer->getKey())
        ->and($applications->forSubject(MembershipSubjectType::Institution, (string) $institution->getKey()))->toHaveCount(1);

    $inviter = User::factory()->create();
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => 'invitee@example.com',
        'role' => MemberRole::Viewer->spatieRoleName(),
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue('seam-invitation-token')->save();

    $invitations = app(MembershipInvitations::class);

    expect($invitations->findByToken('seam-invitation-token')?->email)->toBe('invitee@example.com')
        ->and($invitations->pendingForSubject(MembershipSubjectType::Institution, (string) $institution->getKey()))->toHaveCount(1)
        ->and($invitations->pendingExistsForEmail(MembershipSubjectType::Institution, (string) $institution->getKey(), 'INVITEE@example.com'))->toBeTrue()
        ->and($invitations->find((string) $invitation->getKey())?->id)->toBe((string) $invitation->getKey());
});
