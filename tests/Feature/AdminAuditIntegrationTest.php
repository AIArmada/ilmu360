<?php

use App\Enums\TagType;
use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Filament\Ahli\Resources\References\ReferenceResource as AhliReferenceResource;
use App\Filament\Ahli\Resources\Speakers\SpeakerResource as AhliSpeakerResource;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Filament\Resources\Audits\AuditResource;
use App\Filament\Resources\Authz\UserResource;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Inspirations\InspirationResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Spaces\SpaceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Models\Address;
use App\Models\AiModelPricing;
use App\Models\Audit;
use App\Models\Contact;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSettings;
use App\Models\EventSubmission;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\MediaLink;
use App\Models\MemberInvitation;
use App\Models\MembershipClaim;
use App\Models\ModerationReview;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Series;
use App\Models\SocialMedia;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
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
    Speaker::observe(AuditableObserver::class);
    Tag::observe(AuditableObserver::class);
    Venue::observe(AuditableObserver::class);

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('registers the audits relation manager on audited admin and ahli resources', function () {
    $resources = [
        EventResource::class,
        InstitutionResource::class,
        SpeakerResource::class,
        VenueResource::class,
        SeriesResource::class,
        ReferenceResource::class,
        DonationChannelResource::class,
        TagResource::class,
        ContributionRequestResource::class,
        MembershipClaimResource::class,
        ReportResource::class,
        SpaceResource::class,
        AiModelPricingResource::class,
        InspirationResource::class,
        UserResource::class,
        AhliEventResource::class,
        AhliInstitutionResource::class,
        AhliSpeakerResource::class,
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
        Contact::class,
        ContributionRequest::class,
        DonationChannel::class,
        Event::class,
        EventKeyPerson::class,
        EventSubmission::class,
        EventSettings::class,
        Inspiration::class,
        Institution::class,
        MediaLink::class,
        MemberInvitation::class,
        MembershipClaim::class,
        ModerationReview::class,
        Reference::class,
        Registration::class,
        Report::class,
        Series::class,
        SocialMedia::class,
        Space::class,
        Speaker::class,
        Tag::class,
        User::class,
        Venue::class,
    ];

    foreach ($models as $modelClass) {
        expect((new $modelClass)->getMorphClass())->toBeString();
    }
});

it('records array-backed tag edits in audits', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $tag = Tag::factory()
        ->ofType(TagType::Discipline)
        ->create([
            'name' => ['en' => 'Fiqh', 'ms' => 'Fiqh'],
            'slug' => ['en' => 'fiqh', 'ms' => 'fiqh'],
        ]);

    $this->actingAs($administrator);

    $tag->update([
        'name' => ['en' => 'Usul Fiqh', 'ms' => 'Usul Fiqh'],
    ]);

    $audit = $tag->audits()
        ->where('event', 'updated')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->event)->toBe('updated')
        ->and($audit?->user_id)->toBe($administrator->id)
        ->and($audit?->new_values)->toHaveKey('name')
        ->and(json_decode((string) ($audit?->new_values['name'] ?? ''), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'en' => 'Usul Fiqh',
            'ms' => 'Usul Fiqh',
        ]);
});

it('records moderation review creation in audits', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator);

    $review = ModerationReview::query()->create([
        'event_id' => $event->getKey(),
        'moderator_id' => $administrator->getKey(),
        'decision' => 'rejected',
        'note' => 'Schedule details are incomplete.',
        'reason_code' => 'details_incomplete',
    ]);

    $audit = $review->audits()
        ->where('event', 'created')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe($administrator->getKey())
        ->and($audit?->event)->toBe('created')
        ->and($audit?->new_values['decision'] ?? null)->toBe('rejected')
        ->and($audit?->new_values['reason_code'] ?? null)->toBe('details_incomplete')
        ->and($audit?->new_values['moderator_id'] ?? null)->toBe($administrator->getKey())
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
        'submitted_by' => $administrator->getKey(),
        'submitter_name' => $administrator->name,
        'notes' => 'Submitted from the public contribution flow.',
    ]);

    $audit = $submission->audits()
        ->where('event', 'created')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe($administrator->getKey())
        ->and($audit?->event)->toBe('created')
        ->and($audit?->new_values['submitted_by'] ?? null)->toBe($administrator->getKey())
        ->and($audit?->new_values['submitter_name'] ?? null)->toBe($administrator->name)
        ->and($audit?->new_values['notes'] ?? null)->toBe('Submitted from the public contribution flow.')
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
        ->and($audit?->old_values['password'] ?? null)->toBe('[redacted]')
        ->and($audit?->new_values['password'] ?? null)->toBe('[redacted]');
});
