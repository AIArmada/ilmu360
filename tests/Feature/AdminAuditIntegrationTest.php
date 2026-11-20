<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Events\Models\EventAccessPolicy;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Features\Ai\Models\AiModelPricing;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Models\MediaLink;
use App\Features\Events\Models\Series;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use App\Features\Inspirations\Models\Inspiration;
use App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Moderation\Models\ModerationReview;
use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource as AhliPersonResource;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Features\Persons\Models\Person;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource as AhliReferenceResource;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\Models\Reference;
use App\Features\Registration\Models\Registration;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\Models\Report;
use App\Models\User;
use App\Platform\Auditing\AuditsRelationManager;
use App\Platform\Auditing\EntryPoints\Filament\Resources\Audits\AuditResource;
use App\Platform\Auditing\Models\Audit;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\AuditableObserver;

beforeEach(function () {
    config()->set('audit.console', true);

    Event::observe(AuditableObserver::class);
    EventSubmission::observe(AuditableObserver::class);
    Institution::observe(AuditableObserver::class);
    ModerationReview::observe(AuditableObserver::class);
    Person::observe(AuditableObserver::class);
    Venue::observe(AuditableObserver::class);

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('registers the audits relation manager on audited admin and ahli resources', function () {
    $resources = [
        InstitutionResource::class,
        PersonResource::class,
        SeriesResource::class,
        ReferenceResource::class,
        DonationChannelResource::class,
        ContributionRequestResource::class,
        MembershipApplicationResource::class,
        ReportResource::class,
        SpaceResource::class,
        AiModelPricingResource::class,
        InspirationResource::class,
        UserResource::class,
        AhliInstitutionResource::class,
        AhliPersonResource::class,
        AhliReferenceResource::class,
    ];

    foreach ($resources as $resource) {
        expect($resource::getRelations())->toContain(AuditsRelationManager::class);
    }
});

it('allows super admins to access the global audits resource', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(AuditResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();
});

it('allows super admins to view a global audit record', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator);

    $event->update([
        'title' => $event->title.' (Updated)',
    ]);

    $audit = Audit::query()
        ->where('auditable_type', $event->getMorphClass())
        ->latest('created_at')
        ->firstOrFail();

    $this->get(AuditResource::getUrl('view', ['record' => $audit], panel: 'admin'))
        ->assertSuccessful();
});

it('limits audit visibility to privileged admin roles', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $member = User::factory()->create();
    $record = new Event;

    expect(Gate::forUser($administrator)->allows('audit', $record))->toBeTrue()
        ->and(Gate::forUser($member)->allows('audit', $record))->toBeFalse();
});

it('registers morph aliases for audited models', function () {
    $models = [
        Address::class,
        AiModelPricing::class,
        ContactMethod::class,
        ContributionRequest::class,
        DonationChannel::class,
        Event::class,
        EventKeyPerson::class,
        EventSubmission::class,
        EventAccessPolicy::class,
        Inspiration::class,
        Institution::class,
        MediaLink::class,
        MemberInvitation::class,
        MembershipApplication::class,
        ModerationReview::class,
        Reference::class,
        Registration::class,
        Report::class,
        Series::class,
        SocialProfile::class,
        Space::class,
        Person::class,
        User::class,
        Venue::class,
    ];

    foreach ($models as $modelClass) {
        expect((new $modelClass)->getMorphClass())->toBeString();
    }
});

it('records moderation review creation in audits', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator);

    $review = ModerationReview::query()->create([
        'actionable_type' => (new Event)->getMorphClass(),
        'actionable_id' => $event->getKey(),
        'actioned_by_type' => (new User)->getMorphClass(),
        'actioned_by_id' => $administrator->getKey(),
        'type' => 'reject',
        'notes' => 'Schedule details are incomplete.',
        'reason' => 'details_incomplete',
    ]);

    $audit = $review->audits()
        ->where('event', 'created')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe($administrator->getKey())
        ->and($audit?->event)->toBe('created')
        ->and($audit?->new_values['type'] ?? null)->toBe('reject')
        ->and($audit?->new_values['reason'] ?? null)->toBe('details_incomplete')
        ->and($audit?->new_values['actioned_by_id'] ?? null)->toBe($administrator->getKey())
        ->and($audit?->auditable_type)->toBe($review->getMorphClass())
        ->and($audit?->auditable_id)->toBe($review->getKey());
});

it('records event submission creation in audits', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator);

    $submission = EventSubmission::query()->create([
        'event_id' => $event->getKey(),
        'status' => 'pending',
        'submitted_at' => now(),
        'submitter_type' => $administrator->getMorphClass(),
        'submitter_id' => $administrator->getKey(),
        'submission_data' => [
            'submitter_name' => $administrator->name,
            'notes' => 'Submitted from the public contribution flow.',
        ],
    ]);

    $audit = $submission->audits()
        ->where('event', 'created')
        ->latest('created_at')
        ->first();

    $submissionData = $audit?->new_values['submission_data'] ?? [];
    $submissionData = is_string($submissionData)
        ? json_decode($submissionData, true)
        : $submissionData;

    expect($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe($administrator->getKey())
        ->and($audit?->event)->toBe('created')
        ->and($audit?->new_values['submitter_id'] ?? null)->toBe($administrator->getKey())
        ->and($submissionData)->toBeArray()
        ->and($submissionData['submitter_name'] ?? null)->toBe($administrator->name)
        ->and($submissionData['notes'] ?? null)->toBe('Submitted from the public contribution flow.')
        ->and($audit?->auditable_type)->toBe($submission->getMorphClass())
        ->and($audit?->auditable_id)->toBe($submission->getKey());
});

it('redacts sensitive user fields in audits', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create();

    $this->actingAs($administrator);

    $targetUser->update([
        'password' => 'new-secret-password',
    ]);

    $audit = $targetUser->audits()
        ->where('event', 'updated')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->event)->toBe('updated')
        ->and($audit?->old_values['password'] ?? null)->toBeIn([null, '[redacted]'])
        ->and($audit?->new_values['password'] ?? null)->toBeIn([null, '[redacted]']);
});
