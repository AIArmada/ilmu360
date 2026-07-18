<?php

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Location\NormalizeGoogleMapsInputAction;
use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\InspirationCategory;
use App\Enums\InstitutionType;
use App\Http\Controllers\Api\Frontend\SearchController;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use Database\Factories\EventKeyPersonFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    fakePrayerTimesApi();
    withGlobalOwnerContext(function () {
        $this->seed(PermissionSeeder::class);
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });
});

function assignInstitutionOwnerForFrontendApi(User $user, Institution $institution): void
{
    addTestMember($institution, $user, MemberRole::Owner);
}

function assignSpeakerOwnerForFrontendApi(User $user, Speaker $speaker): void
{
    addTestMember($speaker, $user, MemberRole::Owner);
}

function ensureFrontendApiMalaysiaCountryExists(): string
{
    return (string) ensureTestMalaysiaCountry()->getKey();
}

it('exposes corrected frontend contract metadata', function () {
    ensureFrontendApiMalaysiaCountryExists();

    $manifest = $this->getJson(route('api.client.manifest'))
        ->assertOk()
        ->json('data');
    $contributionUpdateFlow = $manifest['flows']['contribution_update'] ?? [];
    $membershipClaimFlow = $manifest['flows']['membership_claim'] ?? [];
    $followFlow = $manifest['flows']['follow'] ?? [];
    $inspirationFlow = $manifest['flows']['inspirations_random'] ?? [];
    $shareFlow = $manifest['flows']['share'] ?? [];
    $mobileTelemetryFlow = $manifest['flows']['mobile_telemetry'] ?? [];
    $shareAnalyticsFlow = $manifest['flows']['share_analytics'] ?? [];
    $institutionsNearFlow = $manifest['flows']['institutions_near'] ?? [];
    $quickstart = $manifest['ai_quickstart']['read_order'] ?? [];
    $documentationLibrary = collect($manifest['docs']['library'] ?? []);

    $mobileTelemetryContract = $this->getJson(route('api.client.forms.mobile-telemetry'))
        ->assertOk()
        ->json('data');

    $submitEvent = $this->getJson(route('api.client.forms.submit-event'))
        ->assertOk()
        ->json('data');

    $mobileTelemetryEventFields = collect($mobileTelemetryContract['event_fields'] ?? [])->pluck('name')->all();
    $submitEventFields = collect($submitEvent['fields'] ?? [])->pluck('name')->all();
    $submitEventConditionalRules = collect($submitEvent['conditional_rules'] ?? []);

    expect($submitEvent['captcha_required_when_turnstile_enabled'])->toBeTrue()
        ->and($manifest['version'] ?? null)->toBe('2026-04-22')
        ->and($manifest['docs']['ui'] ?? null)->toBe('https://api.ilmu360.test/docs')
        ->and($manifest['docs']['openapi'] ?? null)->toBe('https://api.ilmu360.test/docs.json')
        ->and($manifest['docs']['catalog_endpoint'] ?? null)->toContain('/api/v1/documentation')
        ->and($manifest['docs']['document_endpoint_template'] ?? null)->toContain('/api/v1/documentation/documentId')
        ->and($manifest['surface_sync']['strategy'] ?? null)->toBe('curated_parity')
        ->and($manifest['surface_sync']['default_panel_only_operations'] ?? [])->toContain('delete', 'restore', 'replicate', 'reorder')
        ->and($manifest['surface_sync']['maintenance_triggers'] ?? [])->toContain('Workflow API controller or contract changes')
        ->and($manifest['routing_surfaces']['public']['manifest_endpoint'] ?? null)->toContain('/api/v1/manifest')
        ->and($manifest['routing_surfaces']['admin']['manifest_endpoint'] ?? null)->toContain('/api/v1/admin/manifest')
        ->and($manifest['rules'] ?? [])->toContain('Use UTC timestamps, but send date-only filters in the user local calendar together with timezone context.')
        ->and($manifest['rules'] ?? [])->toContain('Use the admin record route_key returned by admin collection or detail payloads for record-specific schema and mutation paths.')
        ->and($documentationLibrary->pluck('id')->all())->toContain('docs-mobile-api-reference', 'docs-admin-mcp-guide', 'docs-review-and-enhancement-plan')
        ->and($documentationLibrary->firstWhere('id', 'docs-mobile-api-reference')['endpoint'] ?? null)->toContain('/api/v1/documentation/docs-mobile-api-reference')
        ->and($quickstart[0]['endpoint'] ?? null)->toBe('https://api.ilmu360.test/docs.json')
        ->and($submitEventFields)->toContain('event_id', 'scoped_institution_id')
        ->and($submitEventFields)->toContain('cover', 'poster', 'gallery')
        ->and($submitEventFields)->toContain('submission_country_id')
        ->and($submitEventFields)->not->toContain('submission_country_code', 'submission_country_key')
        ->not->toContain('timezone')
        ->and($submitEventConditionalRules->pluck('field')->all())->not->toContain('live_url')
        ->and($submitEventConditionalRules->pluck('field')->all())->not->toContain('submission_country_id')
        ->and(collect($submitEvent['fields'])->firstWhere('name', 'submission_country_id')['allowed_values'])
        ->toContain(ensureFrontendApiMalaysiaCountryExists())
        ->and(collect($submitEvent['fields'])->firstWhere('name', 'notes')['max_length'])->toBe(1000)
        ->and(collect($submitEvent['fields'])->firstWhere('name', 'captcha_token')['required'])->toBeFalse()
        ->and(collect($submitEvent['fields'])->firstWhere('name', 'submitter_email')['required'])->toBeFalse()
        ->and($contributionUpdateFlow['endpoint_template'] ?? null)->toContain('/api/v1/contributions/subjectType/subject/suggest')
        ->and($contributionUpdateFlow['schema_endpoint_template'] ?? null)->toContain('/api/v1/forms/contributions/subjectType/subject/suggest')
        ->and($membershipClaimFlow['endpoint_template'] ?? null)->toContain('/api/v1/membership-applications/subjectType/subject')
        ->and($followFlow['state_endpoint_template'] ?? null)->toContain('/api/v1/follows/type/subject')
        ->and($inspirationFlow['endpoint'] ?? null)->toContain('/api/v1/inspirations/random')
        ->and($shareFlow['payload_endpoint'] ?? null)->toContain('/api/v1/share/payload')
        ->and($shareFlow['track_endpoint'] ?? null)->toContain('/api/v1/share/track')
        ->and($shareFlow['payload_bearer_auth_optional'] ?? null)->toBeTrue()
        ->and($shareFlow['track_bearer_auth_optional'] ?? null)->toBeTrue()
        ->and($shareFlow['authenticated_tracking_required'] ?? null)->toBeFalse()
        ->and($shareFlow['channels'] ?? [])->toContain('copy_link', 'native_share')
        ->and($shareFlow['origins'] ?? [])->toContain('web', 'iosapp', 'android', 'macapp')
        ->and($shareFlow['copy_link_channel'] ?? null)->toBe('copy_link')
        ->and($shareFlow['native_share_channel'] ?? null)->toBe('native_share')
        ->and($mobileTelemetryFlow['endpoint'] ?? null)->toContain('/api/v1/mobile/telemetry/events')
        ->and($mobileTelemetryFlow['schema_endpoint'] ?? null)->toContain('/api/v1/forms/mobile-telemetry')
        ->and($mobileTelemetryFlow['auth_required'] ?? null)->toBeFalse()
        ->and($mobileTelemetryFlow['bearer_auth_optional'] ?? null)->toBeTrue()
        ->and($mobileTelemetryFlow['native_only'] ?? null)->toBeTrue()
        ->and($mobileTelemetryFlow['intended_clients'] ?? [])->toContain('ios', 'ipados', 'android')
        ->and($mobileTelemetryFlow['required_headers'] ?? [])->toContain('X-Ilmu360-Client-Origin')
        ->and($shareAnalyticsFlow['endpoint'] ?? null)->toContain('/api/v1/share/analytics')
        ->and($shareAnalyticsFlow['link_endpoint_template'] ?? null)->toContain('/api/v1/share/analytics/links/link')
        ->and($shareAnalyticsFlow['auth_required'] ?? null)->toBeTrue()
        ->and($shareAnalyticsFlow['filters']['type'] ?? [])->toContain('event', 'institution', 'speaker', 'series', 'reference', 'search', 'page')
        ->and($shareAnalyticsFlow['filters']['sort'] ?? [])->toContain('recent', 'visits', 'signups', 'registrations', 'checkins', 'submissions')
        ->and($institutionsNearFlow['endpoint'] ?? null)->toContain('/api/v1/institutions/near')
        ->and($institutionsNearFlow['near_format'] ?? null)->toBe('lat,lng')
        ->and($institutionsNearFlow['radius_parameter'] ?? null)->toBe('radius_km');

    expect($mobileTelemetryContract['flow'] ?? null)->toBe('mobile_telemetry')
        ->and($mobileTelemetryContract['endpoint'] ?? null)->toContain('/api/v1/mobile/telemetry/events')
        ->and($mobileTelemetryContract['bearer_auth_optional'] ?? null)->toBeTrue()
        ->and($mobileTelemetryContract['native_only'] ?? null)->toBeTrue()
        ->and($mobileTelemetryContract['required_headers'][0]['name'] ?? null)->toBe('X-Ilmu360-Client-Origin')
        ->and($mobileTelemetryContract['required_headers'][0]['allowed_values'] ?? [])->toContain('iosapp', 'ipadosapp', 'androidapp')
        ->and($mobileTelemetryEventFields)->toContain('event_name', 'screen_name', 'component', 'action', 'properties')
        ->and($mobileTelemetryContract['notes'] ?? [])->toContain('Do not use this endpoint for mobile web page views; browser sessions should keep using the web signals tracker.');

    $speakerContract = $this->getJson(route('api.client.forms.contributions.speakers'))
        ->assertOk()
        ->json('data');
    $speakerFields = collect($speakerContract['fields'] ?? [])->pluck('name')->all();

    expect($speakerFields)->toContain('job_title', 'avatar', 'cover', 'address.country_id', 'qualifications', 'institution_id', 'institution_position')
        ->not->toContain('address.country_code', 'address.country_key')
        ->not->toContain('address.line1')
        ->not->toContain('address.google_maps_url')
        ->not->toContain('position')
        ->not->toContain('main');

    $institutionContract = $this->getJson(route('api.client.forms.contributions.institutions'))
        ->assertOk()
        ->json('data');
    $institutionFields = collect($institutionContract['fields'] ?? [])->pluck('name')->all();

    expect($institutionFields)->not->toContain('logo');

    Sanctum::actingAs(User::factory()->create());

    $reportContract = $this->getJson(route('api.client.forms.report'))
        ->assertOk()
        ->json('data');
    $reportEvidenceField = collect($reportContract['fields'] ?? [])->firstWhere('name', 'evidence');

    expect(collect($reportContract['fields'] ?? [])->pluck('name')->all())->toContain('evidence')
        ->and($reportEvidenceField['type'] ?? null)->toBe('array<file>')
        ->and($reportEvidenceField['required'] ?? null)->toBeFalse()
        ->and($reportEvidenceField['accepted_mime_types'] ?? [])->toContain('application/pdf')
        ->and($reportEvidenceField['max_files'] ?? null)->toBe(8);
});

it('clamps public institution directory per_page values to the supported maximum', function () {
    ensureFrontendApiMalaysiaCountryExists();

    Institution::factory()->count(55)->create([
        'status' => 'verified',
    ]);

    $this->getJson('/api/v1/institutions?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', 50)
        ->assertJsonCount(50, 'data');
});

it('filters public institutions by current location radius and returns distance metadata', function () {
    ensureFrontendApiMalaysiaCountryExists();

    $nearInstitution = Institution::factory()->create([
        'name' => 'Masjid Radius Dekat',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($nearInstitution, [
        'lat' => 3.1390,
        'lng' => 101.6869,
    ]);

    $farInstitution = Institution::factory()->create([
        'name' => 'Masjid Radius Jauh',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($farInstitution, [
        'lat' => 3.2600,
        'lng' => 101.8600,
    ]);

    $pendingInstitution = Institution::factory()->create([
        'name' => 'Masjid Radius Pending',
        'status' => 'pending',
    ]);
    syncPrimaryAddressForTest($pendingInstitution, [
        'lat' => 3.1390,
        'lng' => 101.6869,
    ]);

    $response = $this->getJson('/api/v1/institutions?lat=3.1390&lng=101.6869&radius_km=12&per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.location.active', true)
        ->assertJsonPath('meta.location.lat', 3.139)
        ->assertJsonPath('meta.location.lng', 101.6869)
        ->assertJsonPath('meta.location.radius_km', 12)
        ->assertJsonPath('data.0.id', (string) $nearInstitution->id);

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)
        ->toContain('Masjid Radius Dekat')
        ->not->toContain('Masjid Radius Jauh')
        ->not->toContain('Masjid Radius Pending')
        ->and($response->json('data.0.distance_km'))->toBeNumeric()
        ->and((float) $response->json('data.0.distance_km'))->toBeLessThan(1.0);
});

it('supports the nearby institution alias and sparse list fields', function () {
    ensureFrontendApiMalaysiaCountryExists();

    $nearInstitution = Institution::factory()->create([
        'name' => 'Masjid Near Alias',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($nearInstitution, [
        'lat' => 3.1390,
        'lng' => 101.6869,
    ]);

    $farInstitution = Institution::factory()->create([
        'name' => 'Masjid Far Alias',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($farInstitution, [
        'lat' => 3.2600,
        'lng' => 101.8600,
    ]);

    $response = $this->getJson('/api/v1/institutions/near?near=3.1390,101.6869&radius_km=12&fields=id,name,location,distance_km,type')
        ->assertOk()
        ->assertJsonPath('meta.location.active', true);

    $names = collect($response->json('data'))->pluck('name')->all();
    $item = $response->json('data.0');

    expect($names)
        ->toContain('Masjid Near Alias')
        ->not->toContain('Masjid Far Alias')
        ->and(array_keys($item))->toBe(['id', 'name', 'location', 'distance_km', 'type'])
        ->and(data_get($item, 'distance_km'))->toBeNumeric();
});

it('rejects unsupported public institution sparse fields', function () {
    ensureFrontendApiMalaysiaCountryExists();

    $this->getJson('/api/v1/institutions?fields=id,unknown_field')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');
});

it('supports sparse fields on the public speaker directory', function () {
    ensureFrontendApiMalaysiaCountryExists();

    Speaker::factory()->create([
        'status' => 'verified',
        'name' => 'Sparse Speaker',
    ]);

    $response = $this->getJson('/api/v1/speakers?fields=id,name,status,avatar_url,gender')
        ->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'status', 'avatar_url', 'gender']);
});

it('supports sparse fields on the public reference directory', function () {
    Reference::factory()->create([
        'title' => 'Sparse Reference',
        'status' => 'verified',
    ]);

    $response = $this->getJson('/api/v1/references?fields=id,title,front_cover_url,is_following')
        ->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'title', 'front_cover_url', 'is_following']);
});

it('rejects unsupported public reference sparse fields', function () {
    $this->getJson('/api/v1/references?fields=id,unknown_field')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');
});

it('exposes authenticated contribution update contracts and permission-gated direct edit media support', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'description' => 'Community institution',
    ]);
    $institution->addMedia(fakeGeneratedImageUpload('context-cover.jpg', 1600, 900))->toMediaCollection('cover');
    $institution->addMedia(fakeGeneratedImageUpload('context-gallery.jpg', 1600, 900))->toMediaCollection('gallery');

    assignInstitutionOwnerForFrontendApi($owner, $institution);

    Sanctum::actingAs($visitor);

    $visitorResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'institusi',
        'subject' => $institution->slug,
    ]))->assertOk();

    Sanctum::actingAs($owner);

    $ownerResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'institusi',
        'subject' => $institution->slug,
    ]))->assertOk();

    $fields = collect($ownerResponse->json('data.fields'));

    expect($visitorResponse->json('data.can_direct_edit'))->toBeFalse()
        ->and($visitorResponse->json('data.direct_edit_media_fields'))->toBe([])
        ->and($ownerResponse->json('data.can_direct_edit'))->toBeTrue()
        ->and($ownerResponse->json('data.direct_edit_media_fields'))->toBe(['cover', 'gallery'])
        ->and($ownerResponse->json('data.current_media.cover.0.url'))->not->toBeNull()
        ->and($ownerResponse->json('data.current_media.gallery.0.url'))->not->toBeNull()
        ->and($fields->pluck('name')->all())->toContain('description', 'social_media')
        ->and($fields->firstWhere('name', 'type')['allowed_values'])->toContain('masjid')
        ->and($ownerResponse->json('data.initial_state.description'))->toBe('Community institution');
});

it('does not grant institution direct edit access from bearer token abilities alone', function () {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'description' => 'Community institution',
    ]);

    $visitorToken = $visitor->createToken('visitor-device', ['institution.update'])->plainTextToken;

    $response = $this->withToken($visitorToken)
        ->getJson(route('api.client.forms.contributions.suggest', [
            'subjectType' => 'institusi',
            'subject' => $institution->slug,
        ]))->assertOk();

    expect($response->json('data.can_direct_edit'))->toBeFalse()
        ->and($response->json('data.direct_edit_media_fields'))->toBe([]);
});

it('allows institution direct edit access over bearer tokens without token abilities', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'description' => 'Community institution',
    ]);

    assignInstitutionOwnerForFrontendApi($owner, $institution);

    $ownerToken = $owner->createToken('owner-device', [])->plainTextToken;

    $response = $this->withToken($ownerToken)
        ->getJson(route('api.client.forms.contributions.suggest', [
            'subjectType' => 'institusi',
            'subject' => $institution->slug,
        ]))->assertOk();

    expect($response->json('data.can_direct_edit'))->toBeTrue()
        ->and($response->json('data.direct_edit_media_fields'))->toBe(['cover', 'gallery'])
        ->and($response->json('data.initial_state.description'))->toBe('Community institution');
});

it('reflects scoped institution role grants and removals on an existing bearer token', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'description' => 'Community institution',
    ]);

    $token = $user->createToken('institution-role-drift-check', [])->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.client.forms.contributions.suggest', [
            'subjectType' => 'institusi',
            'subject' => $institution->slug,
        ]))
        ->assertOk()
        ->assertJsonPath('data.can_direct_edit', false);

    assignInstitutionOwnerForFrontendApi($user, $institution);

    $this->withToken($token)
        ->getJson(route('api.client.forms.contributions.suggest', [
            'subjectType' => 'institusi',
            'subject' => $institution->slug,
        ]))
        ->assertOk()
        ->assertJsonPath('data.can_direct_edit', true);

    $institution->members()->detach($user);

    $this->withToken($token)
        ->getJson(route('api.client.forms.contributions.suggest', [
            'subjectType' => 'institusi',
            'subject' => $institution->slug,
        ]))
        ->assertOk()
        ->assertJsonPath('data.can_direct_edit', false);
});

it('normalizes event update context to public organizer values and exposes lookup metadata', function () {
    $user = User::factory()->create();
    $startsAt = now()->addDays(3)->setTime(20, 15);
    $endsAt = now()->addDays(3)->setTime(21, 30);
    $institution = Institution::factory()->create([
        'type' => 'masjid',
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'API Contract Event',
        'slug' => 'api-contract-event',
        'status' => 'approved',
        'institution_id' => $institution->getKey(),
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'delivery_mode' => 'physical',
        'visibility' => 'public',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]))->assertOk();

    $initialStateKeys = array_keys($response->json('data.initial_state'));
    $fields = collect($response->json('data.fields'));
    $fieldNames = $fields->pluck('name')->all();

    expect($response->json('data.initial_state.primary_organizer_id'))->toBe((string) $institution->getKey())
        ->and($response->json('data.initial_state.event_date'))->toBe($event->fresh()->starts_at?->timezone('Asia/Kuala_Lumpur')->toDateString())
        ->and($response->json('data.initial_state.custom_time'))->toBe($event->fresh()->starts_at?->timezone('Asia/Kuala_Lumpur')->format('H:i'))
        ->and($fields->firstWhere('name', 'primary_organizer_id')['type'])->toBe('uuid')
        ->and($fields->firstWhere('name', 'language_ids')['catalog'])->toContain('/api/v1/catalogs/languages')
        ->and($fields->firstWhere('name', 'speaker_ids')['catalog'])->toContain('/api/v1/catalogs/submit-speakers')
        ->and($fieldNames)->toContain(
            'event_date',
            'prayer_time',
            'custom_time',
            'end_time',
            'primary_organizer_id',
            'location_same_as_institution',
            'location_type',
            'location_institution_id',
            'location_venue_id',
        )
        ->and($fieldNames)->not->toContain('starts_at', 'ends_at', 'institution_id', 'venue_id', 'timing_mode', 'prayer_reference', 'prayer_offset', 'prayer_display_text', 'primary_organizer_kind', 'primary_organizer_institution_id', 'primary_organizer_speaker_id')
        ->and($initialStateKeys)->not->toContain('starts_at', 'ends_at', 'institution_id', 'venue_id', 'timing_mode', 'prayer_reference', 'prayer_offset', 'prayer_display_text', 'primary_organizer_kind', 'primary_organizer_institution_id', 'primary_organizer_speaker_id');
});

it('exposes event direct edit media support for authorized public updaters', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'type' => 'masjid',
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'institution_id' => $institution->getKey(),
        'starts_at' => now()->addDays(4)->setTime(20, 0),
        'ends_at' => now()->addDays(4)->setTime(21, 0),
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    $event->addMedia(UploadedFile::fake()->image('context-cover.jpg', 1600, 900))->toMediaCollection('cover');
    $event->addMedia(UploadedFile::fake()->image('context-poster.jpg', 1200, 1600))->toMediaCollection('poster');
    $event->addMedia(UploadedFile::fake()->image('context-gallery.jpg', 1600, 900))->toMediaCollection('gallery');

    assignInstitutionOwnerForFrontendApi($owner, $institution);

    Sanctum::actingAs($visitor);

    $visitorResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]))->assertOk();

    Sanctum::actingAs($owner);

    $ownerResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]))->assertOk();

    expect($visitorResponse->json('data.can_direct_edit'))->toBeFalse()
        ->and($visitorResponse->json('data.direct_edit_media_fields'))->toBe([])
        ->and($ownerResponse->json('data.can_direct_edit'))->toBeTrue()
        ->and($ownerResponse->json('data.direct_edit_media_fields'))->toBe(['cover', 'poster', 'gallery'])
        ->and($ownerResponse->json('data.current_media.cover.0.url'))->not->toBeNull()
        ->and($ownerResponse->json('data.current_media.poster.0.url'))->not->toBeNull()
        ->and($ownerResponse->json('data.current_media.gallery.0.thumb_url'))->not->toBeNull();
});

it('accepts helper-shaped event timing updates on public contribution suggestions', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'type' => 'masjid',
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'institution_id' => $institution->getKey(),
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(20, 0),
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $targetDate = now()->addDays(5)->toDateString();

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]), [
        'event_date' => $targetDate,
        'prayer_time' => 'lain_waktu',
        'custom_time' => '20:15',
        'end_time' => '21:30',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $event = $event->fresh();

    expect($event->starts_at?->timezone('Asia/Kuala_Lumpur')->toDateString())->toBe($targetDate)
        ->and($event->starts_at?->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('20:15')
        ->and($event->ends_at?->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('21:30');
});

it('allows direct contribution updates to clear nullable institution fields', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'description' => 'Old description',
    ]);

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => 'institusi',
        'subject' => $institution->slug,
    ]), [
        'description' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    expect($institution->fresh()->description)->toBeNull();
});

it('exposes speaker avatar direct edit media support for authorized public updaters', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $speaker->addMedia(UploadedFile::fake()->image('context-avatar.jpg', 1200, 1200))->toMediaCollection('avatar');
    $speaker->addMedia(UploadedFile::fake()->image('context-cover.jpg', 1200, 1500))->toMediaCollection('cover');
    $speaker->addMedia(UploadedFile::fake()->image('context-gallery.jpg', 1600, 900))->toMediaCollection('gallery');

    assignSpeakerOwnerForFrontendApi($owner, $speaker);

    Sanctum::actingAs($visitor);

    $visitorResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]))->assertOk();

    Sanctum::actingAs($owner);

    $ownerResponse = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]))->assertOk();

    expect($visitorResponse->json('data.can_direct_edit'))->toBeFalse()
        ->and($visitorResponse->json('data.direct_edit_media_fields'))->toBe([])
        ->and($ownerResponse->json('data.can_direct_edit'))->toBeTrue()
        ->and($ownerResponse->json('data.direct_edit_media_fields'))->toBe(['avatar', 'cover', 'gallery'])
        ->and($ownerResponse->json('data.current_media.avatar.0.url'))->not->toBeNull()
        ->and($ownerResponse->json('data.current_media.cover.0.url'))->not->toBeNull()
        ->and($ownerResponse->json('data.current_media.gallery.0.thumb_url'))->not->toBeNull();
});

it('allows direct institution gallery uploads on public contribution update suggestions', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'institusi',
        'subject' => $institution->slug,
    ]), [
        'gallery' => [fakeGeneratedImageUpload('institution-gallery.jpg', 1600, 900)],
    ], [
        'Accept' => 'application/json',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    expect($institution->fresh()?->getMedia('gallery'))->toHaveCount(1);
});

it('returns only region address keys in the speaker suggest context state', function () {
    $owner = User::factory()->create();
    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $country = AddressCountry::query()->findOrFail($countryId);
    $state = createTestAddressArea('Negeri Konteks Penceramah API', 1, null, $country);
    $district = createTestAddressArea('Daerah Konteks Penceramah API', 2, $state, $country);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
        'admin_area_1_id' => (string) $district->getKey(),
        'admin_area_2_id' => null,
        'admin_area_3_id' => null,
        'admin_area_4_id' => null,
        'line1' => 'Jalan Lama 1',
        'line2' => 'Taman Lama',
        'postcode' => '50000',
        'lat' => 3.139,
        'lng' => 101.6869,
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
        'google_place_id' => 'speaker-suggest-place',
        'waze_url' => 'https://waze.com/ul?ll=3.1390,101.6869',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $response = $this->getJson(route('api.client.forms.contributions.suggest', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]))->assertOk();

    expect($response->json('data.initial_state.address'))->toBe([
        'country_id' => $countryId,
        'admin_area_1_id' => (string) $district->getKey(),
        'admin_area_2_id' => null,
    ]);
});

it('rejects unchanged speaker region-only address round trips as validation errors', function () {
    $owner = User::factory()->create();
    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $country = AddressCountry::query()->findOrFail($countryId);
    $state = createTestAddressArea('Negeri Pusing Balik Penceramah API', 1, null, $country);
    $district = createTestAddressArea('Daerah Pusing Balik Penceramah API', 2, $state, $country);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
        'admin_area_1_id' => (string) $district->getKey(),
        'admin_area_2_id' => null,
        'admin_area_3_id' => null,
        'admin_area_4_id' => null,
        'line1' => 'Alamat Warisan',
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'address' => [
            'country_id' => $countryId,
            'admin_area_1_id' => (string) $district->getKey(),
            'admin_area_2_id' => null,
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['data']);

    $speaker = $speaker->fresh('addresses');
    expect($speaker?->primaryAddress()?->line1)->toBe('Alamat Warisan')
        ->and($speaker?->primaryAddress()?->google_maps_url)->toBe('https://maps.google.com/?q=3.1390,101.6869');
});

it('preserves hidden speaker address details during region-only direct updates', function () {
    $owner = User::factory()->create();
    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $country = AddressCountry::query()->findOrFail($countryId);
    $state = createTestAddressArea('Negeri Kekal Butiran Penceramah API', 1, null, $country);
    $district = createTestAddressArea('Daerah Kekal Butiran Penceramah API', 2, $state, $country);
    $updatedDistrict = createTestAddressArea('Daerah Baharu Penceramah API', 2, $state, $country);

    $speaker = Speaker::factory()->create([
        'name' => 'Penceramah Lama API',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
        'admin_area_1_id' => (string) $district->getKey(),
        'admin_area_2_id' => null,
        'admin_area_3_id' => null,
        'admin_area_4_id' => null,
        'line1' => 'Alamat Warisan',
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'name' => 'Penceramah Dikemas Kini API',
        'address' => [
            'country_id' => $countryId,
            'admin_area_1_id' => (string) $updatedDistrict->getKey(),
            'admin_area_2_id' => null,
        ],
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $speaker = $speaker->fresh('addresses');

    $expectedGoogleMapsUrl = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
    ])['google_maps_url'];

    expect($speaker?->name)->toBe('Penceramah Dikemas Kini API')
        ->and($speaker?->primaryAddress()?->admin_area_1_id)->toBe((string) $updatedDistrict->getKey())
        ->and($speaker?->primaryAddress()?->admin_area_2_id)->toBeNull()
        ->and($speaker?->primaryAddress()?->line1)->toBe('Alamat Warisan')
        ->and($speaker?->primaryAddress()?->google_maps_url)->toBe($expectedGoogleMapsUrl);
});

it('allows direct speaker avatar uploads on public contribution update suggestions', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'avatar' => fakeGeneratedImageUpload('speaker-avatar.jpg', 1200, 1200),
    ], [
        'Accept' => 'application/json',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $speaker = $speaker->fresh(['media']);

    expect($speaker?->hasMedia('avatar'))->toBeTrue()
        ->and($speaker?->public_avatar_url)->not->toBe('');
});

it('allows direct speaker cover uploads on public contribution update suggestions', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $coverUpload = fakeGeneratedImageUpload('speaker-cover.png', 1200, 1200);

    $owner = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'cover' => $coverUpload,
    ], [
        'Accept' => 'application/json',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $speaker = $speaker->fresh(['media']);

    expect($speaker?->hasMedia('cover'))->toBeTrue()
        ->and($speaker?->getFirstMediaUrl('cover'))->not->toBe('');
});

it('allows direct speaker gallery uploads on public contribution update suggestions', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'gallery' => [fakeGeneratedImageUpload('speaker-gallery.jpg', 1200, 1200)],
    ], [
        'Accept' => 'application/json',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    expect($speaker->fresh()?->getMedia('gallery'))->toHaveCount(1);
});

it('rejects unsupported speaker media files on public contribution update suggestions', function () {
    $owner = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    assignSpeakerOwnerForFrontendApi($owner, $speaker);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'penceramah',
        'subject' => $speaker->slug,
    ]), [
        'poster' => fakeGeneratedImageUpload('speaker-poster.jpg'),
    ], [
        'Accept' => 'application/json',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files']);
});

it('maps public event organizer values back to persistence classes during direct updates', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'type' => 'masjid',
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'starts_at' => now()->setTimezone('Asia/Kuala_Lumpur')->startOfDay()->addHours(10)->utc(),
        'ends_at' => now()->setTimezone('Asia/Kuala_Lumpur')->startOfDay()->addHours(12)->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'live_url' => 'https://live.example.test/watch',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'delivery_mode' => 'physical',
        'visibility' => 'public',
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]), [
        'primary_organizer_id' => $speaker->getKey(),
        'live_url' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    expect($event->fresh()->live_url)->toBeNull();
});

it('allows direct event cover, poster, and gallery uploads on public contribution update suggestions', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'institution_id' => $institution->getKey(),
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]), [
        'cover' => fakeGeneratedImageUpload('event-cover.jpg', 1600, 900),
        'poster' => fakeGeneratedImageUpload('event-poster.jpg', 1200, 1500),
        'gallery' => [fakeGeneratedImageUpload('event-gallery.jpg', 1200, 800)],
    ], [
        'Accept' => 'application/json',
    ])
        ->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $event = $event->fresh(['media']);

    expect($event?->getMedia('cover'))->toHaveCount(1)
        ->and($event?->getMedia('poster'))->toHaveCount(1)
        ->and($event?->getMedia('gallery'))->toHaveCount(1);
});

it('rejects direct event cover and poster uploads with invalid aspect ratios', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'institution_id' => $institution->getKey(),
    ]);

    withGlobalOwnerContext(fn () => $event->setPrimaryOrganizer($institution));

    assignInstitutionOwnerForFrontendApi($owner, $institution);
    Sanctum::actingAs($owner);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'majlis',
        'subject' => $event->slug,
    ]), [
        'cover' => fakeGeneratedImageUpload('event-cover-invalid.jpg', 1200, 800),
        'poster' => fakeGeneratedImageUpload('event-poster-invalid.jpg', 1600, 900),
    ], [
        'Accept' => 'application/json',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cover', 'poster']);
});

it('searches speakers api by formatted title parts used on the public directory', function () {
    $matchingSpeaker = Speaker::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
    ]);

    $otherSpeaker = Speaker::factory()->create([
        'name' => 'Fatimah Binti Omar',
        'status' => 'verified',
    ]);

    $response = $this->getJson('/api/v1/speakers?search='.urlencode('syeikhul maqari'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain((string) $matchingSpeaker->id)
        ->not->toContain((string) $otherSpeaker->id);
});

it('falls back to local speaker and institution search on the frontend unified search api when typesense fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Nur Hikmah',
        'status' => 'verified',
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Nur Hikmah Hassan',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);
    config()->set('scout.driver', 'typesense');

    $this->app->bind(SpeakerSearchService::class, fn (): SpeakerSearchService => new class extends SpeakerSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $this->app->bind(InstitutionSearchService::class, fn (): InstitutionSearchService => new class extends InstitutionSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $response = $this->getJson('/api/v1/search?search='.urlencode('Nur Hikmah'))
        ->assertOk();

    expect(collect($response->json('data.speakers.items'))->pluck('id')->all())
        ->toContain((string) $speaker->id)
        ->and(collect($response->json('data.institutions.items'))->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('returns full unified search totals while limiting speaker and institution previews', function () {
    foreach (range(1, 6) as $index) {
        $speaker = Speaker::factory()->create([
            'name' => sprintf('Audit Search Total Speaker %d', $index),
            'honorific' => null,
            'pre_nominal' => [],
            'post_nominal' => [],
            'qualifications' => [],
            'status' => 'verified',
        ]);

        app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);

        Institution::factory()->create([
            'name' => sprintf('Audit Search Total Institution %d', $index),
            'status' => 'verified',
        ]);
    }

    Speaker::factory()->create([
        'name' => 'Other Search Speaker',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Other Search Institution',
        'status' => 'verified',
    ]);

    $response = $this->getJson('/api/v1/search?search='.urlencode('Audit Search Total'))
        ->assertOk();

    expect($response->json('data.speakers.total'))->toBe(6)
        ->and($response->json('data.institutions.total'))->toBe(6)
        ->and($response->json('data.speakers.items'))->toHaveCount(4)
        ->and($response->json('data.institutions.items'))->toHaveCount(4);
});

it('uses the same fuzzy speaker and institution resolution in the unified search api as the directory endpoints', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);

    $speakerDirectoryResponse = $this->getJson('/api/v1/speakers?search='.urlencode('Aisyh'))
        ->assertOk();
    $speakerUnifiedResponse = $this->getJson('/api/v1/search?search='.urlencode('Aisyh'))
        ->assertOk();

    expect(collect($speakerDirectoryResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $speaker->id)
        ->and(collect($speakerUnifiedResponse->json('data.speakers.items'))->pluck('id')->all())
        ->toContain((string) $speaker->id);

    $institutionDirectoryResponse = $this->getJson('/api/v1/institutions?search='.urlencode('Hidayh'))
        ->assertOk();
    $institutionUnifiedResponse = $this->getJson('/api/v1/search?search='.urlencode('Hidayh'))
        ->assertOk();

    expect(collect($institutionDirectoryResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $institution->id)
        ->and(collect($institutionUnifiedResponse->json('data.institutions.items'))->pluck('id')->all())
        ->toContain((string) $institution->id);
});

it('falls back to local speaker directory search when typesense fails', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'honorific' => null,
        'pre_nominal' => ['syeikhul_maqari'],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);
    config()->set('scout.driver', 'typesense');

    $this->app->bind(SpeakerSearchService::class, fn (): SpeakerSearchService => new class extends SpeakerSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $this->getJson('/api/v1/speakers?search='.urlencode('syeikhul maqari'))
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $speaker->id);
});

it('falls back to database institution directory search when typesense fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
    ]);

    config()->set('scout.driver', 'typesense');

    $this->app->bind(InstitutionSearchService::class, fn (): InstitutionSearchService => new class extends InstitutionSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $this->getJson('/api/v1/institutions?search='.urlencode('Masjid Biru'))
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $institution->id);
});

it('serializes event list payloads with card image metadata for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Poster API Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $event->addMedia(fakeGeneratedImageUpload('event-poster.jpg', 1200, 1600))
        ->toMediaCollection('poster');

    $item = Closure::bind(
        fn (): array => $this->eventListData($event->fresh(['institution.media', 'venue', 'speakers.media', 'keyPeople.speaker'])),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'has_poster'))->toBeTrue()
        ->and(data_get($item, 'poster_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'card_image_url'))->toBeString()->not->toBe('');
});

it('returns card image metadata on public events index responses', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'type' => 'masjid',
        'status' => 'verified',
    ]);

    $posterEvent = Event::factory()->for($institution)->create([
        'title' => 'Home Poster Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $posterEvent->addMedia(fakeGeneratedImageUpload('home-poster.jpg', 1200, 1600))
        ->toMediaCollection('poster');

    $speaker = Speaker::factory()->create([
        'gender' => 'male',
    ]);
    $posterEvent->speakers()->attach($speaker);

    $placeholderEvent = Event::factory()->create([
        'title' => 'Home Placeholder Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $posterResponse = $this->getJson('/api/v1/events?include=institution,venue,speakers&filter[search]=Home%20Poster%20Event&per_page=1')
        ->assertOk();
    $placeholderResponse = $this->getJson('/api/v1/events?include=institution,venue,speakers&filter[search]=Home%20Placeholder%20Event&per_page=1')
        ->assertOk();

    $posterItem = $posterResponse->json('data.0');

    expect($posterResponse->json('data.0.has_poster'))->toBeTrue()
        ->and($posterResponse->json('data.0.card_image_url'))->toBeString()->not->toBe('')
        ->and($posterResponse->json('data.0.poster_url'))->toBeString()->not->toBe('')
        ->and(array_keys($posterItem))->not->toContain('media', 'references', 'key_people')
        ->and(data_get($posterItem, 'institution.media'))->toBeNull()
        ->and(data_get($posterItem, 'institution.type'))->toBe('masjid')
        ->and(data_get($posterItem, 'speakers.0.id'))->toBe((string) $speaker->getKey())
        ->and(data_get($posterItem, 'speakers.0.gender'))->toBe('male')
        ->and(data_get($posterItem, 'speakers.0.pivot'))->toBeNull()
        ->and($placeholderResponse->json('data.0.has_poster'))->toBeFalse()
        ->and($placeholderResponse->json('data.0.card_image_url'))->toContain('images/placeholders/event.png')
        ->and($placeholderResponse->json('data.0.poster_url'))->toBeNull();
});

it('serializes institution directory payloads with card media aliases for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    $malaysiaId = ensureFrontendApiMalaysiaCountryExists();
    $user = User::factory()->create();

    $institution = Institution::factory()->create([
        'name' => 'Masjid API Directory',
        'type' => 'masjid',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, [
        'country_id' => $malaysiaId,
    ]);

    $institution->addMedia(fakeGeneratedImageUpload('directory-logo.jpg', 640, 640))
        ->toMediaCollection('logo');

    $institution->addMedia(fakeGeneratedImageUpload('directory-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    $user->follow($institution);

    $directoryInstitution = $institution->fresh(['addresses.country', 'media']);

    expect($directoryInstitution)->not->toBeNull();

    $directoryInstitution?->forceFill([
        'events_count' => withGlobalOwnerContext(fn () => Event::query()
            ->where('institution_id', $institution->getKey())
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public)
            ->count()),
    ]);

    $item = Closure::bind(
        fn (): array => $this->institutionListData($directoryInstitution, $user),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'display_name'))->toBe($institution->display_name)
        ->and(data_get($item, 'type'))->toBe('masjid')
        ->and(data_get($item, 'events_count'))->toBe(1)
        ->and(data_get($item, 'public_image_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'logo_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'cover_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'is_following'))->toBeTrue()
        ->and(array_key_exists('location', $item))->toBeTrue()
        ->and(array_key_exists('event_count', $item))->toBeFalse()
        ->and(array_key_exists('image_url', $item))->toBeFalse()
        ->and(array_key_exists('location_text', $item))->toBeFalse();
});

it('returns authenticated follow state in the frontend institution api', function () {
    $user = User::factory()->create();

    $institution = Institution::factory()->create([
        'name' => 'Masjid Follow Institution',
        'status' => 'verified',
    ]);

    $user->follow($institution);

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.institutions.index', ['search' => 'Masjid Follow Institution']))
        ->assertOk()
        ->assertJsonPath('data.0.is_following', true);
});

it('returns the total followed institution count for the full institution query, not just the current page', function () {
    $user = User::factory()->create();

    $followedInstitutions = Institution::factory()->count(3)->create([
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Unfollowed Institution',
        'status' => 'verified',
    ]);

    foreach ($followedInstitutions as $institution) {
        $user->follow($institution);
    }

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.institutions.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 4)
        ->assertJsonPath('meta.following.total', 3);
});

it('supports server-side filtering to only followed institutions in the frontend institution api', function () {
    $user = User::factory()->create();

    $followedInstitution = Institution::factory()->create([
        'name' => 'Followed Institution Only',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Not Followed Institution',
        'status' => 'verified',
    ]);

    $user->follow($followedInstitution);

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.institutions.index', ['following' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $followedInstitution->slug)
        ->assertJsonPath('data.0.is_following', true)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.following.total', 1);
});

it('returns enum-backed institution type filters and supports server-side type filtering in the frontend institution api', function () {
    $masjid = Institution::factory()->create([
        'name' => 'Masjid Type Filter Match',
        'status' => 'verified',
        'type' => InstitutionType::Masjid,
    ]);

    Institution::factory()->create([
        'name' => 'Surau Type Filter Miss',
        'status' => 'verified',
        'type' => InstitutionType::Surau,
    ]);

    $response = $this->getJson(route('api.client.institutions.index', ['type' => InstitutionType::Masjid->value]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $masjid->slug)
        ->assertJsonPath('meta.pagination.total', 1);

    expect($response->json('meta.types'))->toBe(array_map(
        static fn (InstitutionType $type): array => [
            'value' => $type->value,
            'label' => $type->getLabel(),
        ],
        InstitutionType::cases(),
    ));
});

it('bumps the institution directory cache version when institution records change', function () {
    $institution = Institution::factory()->create([
        'name' => 'Institution Cache Version',
        'nickname' => 'ICV',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $institution->update([
        'nickname' => 'ICV Updated',
    ]);

    $updatedVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($initialVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->not->toBe($initialVersion);
});

it('serves the institution directory cache metadata without requiring country timestamps', function () {
    ensureFrontendApiMalaysiaCountryExists();

    Institution::factory()->create([
        'name' => 'Institution Cache Metadata',
        'status' => 'verified',
    ]);

    $response = $this->getJson(route('api.client.institutions.index'))
        ->assertOk();

    expect($response->json('meta.cache.version'))->toBeString()->not->toBe('');
});

it('serves the speaker directory cache metadata without requiring country timestamps', function () {
    ensureFrontendApiMalaysiaCountryExists();

    Speaker::factory()->create([
        'name' => 'Speaker Cache Metadata',
        'status' => 'verified',
    ]);

    $response = $this->getJson(route('api.client.speakers.index'))
        ->assertOk();

    expect($response->json('meta.cache.version'))->toBeString()->not->toBe('');
});

it('bumps the institution directory cache version when institution media changes', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'name' => 'Institution Cache Media',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $institution->addMedia(fakeGeneratedImageUpload('institution-cache-logo.jpg', 800, 800))
        ->toMediaCollection('logo');

    $updatedVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('');
});

it('bumps the institution directory cache version when institution addresses change', function () {
    $malaysiaId = ensureFrontendApiMalaysiaCountryExists();

    $institution = Institution::factory()->create([
        'name' => 'Institution Cache Address',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    syncPrimaryAddressForTest($institution, [
        'country_id' => $malaysiaId,
        'line1' => 'Alamat Direktori Baharu',
    ]);

    $updatedVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('');
});

it('bumps the institution directory cache version when public institution events change', function () {
    $institution = Institution::factory()->create([
        'name' => 'Institution Cache Event',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $updatedVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('');
});

it('keeps placeholder institution imagery when no real media exists', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    $malaysiaId = ensureFrontendApiMalaysiaCountryExists();

    $institution = Institution::factory()->create([
        'name' => 'Masjid Tanpa Media',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, [
        'country_id' => $malaysiaId,
    ]);

    $directoryInstitution = $institution->fresh(['addresses.country', 'media']);

    expect($directoryInstitution)->not->toBeNull();

    $item = Closure::bind(
        fn (): array => $this->institutionListData($directoryInstitution),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'cover_url'))->toBeNull()
        ->and(data_get($item, 'logo_url'))->toBeString()->toContain('/images/placeholders/institution.png')
        ->and(data_get($item, 'public_image_url'))->toBe(data_get($item, 'logo_url'))
        ->and(array_key_exists('image_url', $item))->toBeFalse();
});

it('exposes the institution public image url in the frontend institution detail payload', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Detail API',
        'type' => 'masjid',
        'status' => 'verified',
    ]);

    $institution->addMedia(fakeGeneratedImageUpload('detail-logo.jpg', 800, 800))
        ->toMediaCollection('logo');

    $response = $this->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk();

    expect($response->json('data.institution.media.public_image_url'))->toBe($institution->public_image_url)
        ->and($response->json('data.institution.media.cover_url'))->toBeNull()
        ->and($response->json('data.institution.media.public_image_url'))->toBe($response->json('data.institution.media.logo_url'))
        ->and($response->json('data.institution.type'))->toBe('masjid');
});

it('returns institution follow state on the authenticated detail route response', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Follow State API',
        'status' => 'verified',
    ]);

    $user->follow($institution);

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk()
        ->assertJsonPath('data.institution.followers_count', 1)
        ->assertJsonPath('data.institution.is_following', true);
});

it('returns institution follow state on the detail route for bearer token requests', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Bearer Follow State API',
        'status' => 'verified',
    ]);

    $user->follow($institution);

    $token = $user->createToken('mobile-app')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk()
        ->assertJsonPath('data.institution.followers_count', 1)
        ->assertJsonPath('data.institution.is_following', true);
});

it('serializes institution detail payloads with address and donation metadata for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $user = User::factory()->create();
    $country = AddressCountry::query()->findOrFail($countryId);
    $geo = createTestPackageGeography('Pahang Detail API', 'Temerloh Detail API', 'Lanchang Detail API', country: $country);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Detail DTO',
        'status' => 'verified',
        'description' => 'Institution detail serializer coverage',
    ]);

    $institution->addMedia(fakeGeneratedImageUpload('detail-dto-logo.jpg', 800, 800))
        ->toMediaCollection('logo');
    $institution->addMedia(fakeGeneratedImageUpload('detail-dto-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    syncPrimaryAddressForTest($institution, [
        ...$geo['address'],
        'line1' => 'Jalan Masjid 1',
        'postcode' => '28000',
        'lat' => 3.4501,
        'lng' => 102.4194,
        'google_maps_url' => 'https://maps.google.com/?q=3.4501,102.4194',
        'waze_url' => 'https://waze.com/ul?ll=3.4501,102.4194',
    ]);

    $donationChannel = $institution->donationChannels()->create(DonationChannel::factory()->raw([
        'label' => 'Tabung Utama',
        'recipient' => 'Masjid Detail DTO',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'bank_code' => 'MBB',
        'account_number' => '1234567890',
        'status' => 'verified',
        'is_default' => true,
    ]));

    $donationChannel->addMedia(fakeGeneratedImageUpload('institution-qr.png', 600, 600))
        ->toMediaCollection('qr');

    $user->follow($institution);

    $detailInstitution = $institution->fresh([
        'media',
        'addresses.country',
        'contactMethods',
        'socialProfiles',
        'donationChannels.media',
    ]);

    expect($detailInstitution)->not->toBeNull();

    $item = Closure::bind(
        fn (): array => $this->institutionDetailData($detailInstitution, $user),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'address.country_id'))->toBe($countryId)
        ->and(data_get($item, 'country.iso2'))->toBe('MY')
        ->and(data_get($item, 'country.key'))->toBe('malaysia')
        ->and(data_get($item, 'map_lat'))->toBe(3.4501)
        ->and(data_get($item, 'map_lng'))->toBe(102.4194)
        ->and(data_get($item, 'is_following'))->toBeTrue()
        ->and(data_get($item, 'speaker_count'))->toBe(0)
        ->and(data_get($item, 'media.public_image_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'media.logo_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'media.cover_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'donation_channels'))->toHaveCount(1)
        ->and(data_get($item, 'donation_channels.0.label'))->toBe('Tabung Utama')
        ->and(data_get($item, 'donation_channels.0.method_display'))->toBe('Bank Account')
        ->and(data_get($item, 'donation_channels.0.is_default'))->toBeTrue()
        ->and(data_get($item, 'donation_channels.0.qr_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'waze_url'))->toContain('waze.com');
});

it('exposes 7-item institution detail lists with canonical address lines and qr urls', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $malaysiaId = ensureFrontendApiMalaysiaCountryExists();
    $country = AddressCountry::query()->findOrFail($malaysiaId);
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam', country: $country);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Detail Payload',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($institution, [
        ...$geo['address'],
        'line1' => 'Persiaran Masjid',
        'line2' => 'Seksyen 14',
        'postcode' => '40000',
        'lat' => 3.0733,
        'lng' => 101.5185,
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=3.0733%2C101.5185',
        'waze_url' => 'https://www.waze.com/ul?ll=3.0733,101.5185&navigate=yes',
    ]);

    $channel = $institution->donationChannels()->create([
        'method' => 'bank_account',
        'bank_code' => 'BIMB',
        'bank_name' => 'Bank Islam',
        'account_number' => '123456789012',
        'recipient' => 'Tabung Masjid Detail',
        'label' => 'QR Infaq',
        'status' => 'verified',
    ]);

    $channel->addMedia(fakeGeneratedImageUpload('qr.png', 300, 300))
        ->toMediaCollection('qr');

    foreach (range(1, 8) as $index) {
        Event::factory()->create([
            'institution_id' => $institution->id,
            'status' => 'approved',
            'visibility' => EventVisibility::Public->value,
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDays($index),
        ]);
    }

    foreach (range(1, 8) as $index) {
        Event::factory()->create([
            'institution_id' => $institution->id,
            'status' => 'approved',
            'visibility' => EventVisibility::Public->value,
            'published_at' => now()->subDays(2),
            'starts_at' => now()->subDays($index),
        ]);
    }

    $response = $this->getJson(route('api.client.institutions.show', [
        'institutionKey' => $institution->slug,
        'upcoming_per_page' => 7,
        'past_per_page' => 7,
    ]))->assertOk();

    expect($response->json('data.institution.address_line'))->toBe('Shah Alam, Petaling, Selangor')
        ->and($response->json('data.institution.donation_channels.0.qr_url'))->toBeString()
        ->and($response->json('data.institution.donation_channels.0.qr_full_url'))->toBe($channel->getFirstMediaUrl('qr'))
        ->and(count($response->json('data.upcoming_events')))->toBe(7)
        ->and(count($response->json('data.past_events')))->toBe(7)
        ->and($response->json('data.upcoming_total'))->toBe(8)
        ->and($response->json('data.past_total'))->toBe(8);

    $response->assertJsonMissingPath('data.institution.street_address_line')
        ->assertJsonMissingPath('data.institution.locality_address_line')
        ->assertJsonMissingPath('data.institution.regional_address_line');
});

it('rejects unsupported files on public contribution update suggestions', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($user);

    $this->post(route('api.client.contributions.suggest.store', [
        'subjectType' => 'institusi',
        'subject' => $institution->slug,
    ]), [
        'cover' => fakeGeneratedImageUpload('institution-cover.jpg'),
    ], [
        'Accept' => 'application/json',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files']);
});

it('does not infer country defaults for frontend form contracts', function () {
    $singapore = AddressCountry::query()->firstOrCreate(
        ['iso2' => 'SG'],
        [
            'name' => 'Singapore',
            'iso3' => 'SGP',
            'entity_type' => 'country',
            'timezones' => ['Asia/Singapore'],
        ],
    );

    $submitEvent = $this->withHeader('X-Timezone', 'Asia/Singapore')
        ->getJson(route('api.client.forms.submit-event'))
        ->assertOk()
        ->json('data');

    $submitInstitution = $this->withHeader('X-Timezone', 'Asia/Singapore')
        ->getJson(route('api.client.forms.contributions.institutions'))
        ->assertOk()
        ->json('data');

    $submissionCountryField = collect($submitEvent['fields'])->firstWhere('name', 'submission_country_id');

    expect(data_get($submitEvent, 'defaults.submission_country_id'))->toBeNull()
        ->and($submissionCountryField['type'])->toBe('uuid')
        ->and($submissionCountryField['allowed_values'])->toContain((string) $singapore->getKey())
        ->and(data_get($submitInstitution, 'defaults.address.country_id'))->toBeNull();
});

it('creates institution contribution requests through the frontend api', function () {
    $user = User::factory()->create();
    $countryId = ensureFrontendApiMalaysiaCountryExists();
    Sanctum::actingAs($user);

    $this->postJson(route('api.client.contributions.institutions.store'), [
        'type' => 'masjid',
        'name' => 'Masjid API',
        'nickname' => 'API',
        'description' => '<p>Institution description</p>',
        'address' => [
            'country_id' => $countryId,
            'google_maps_url' => 'https://maps.google.com/?q=masjid+api',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('message', __('Thank you. Your institution submission has been received. We will notify you if it is approved or rejected.'))
        ->assertJsonPath('data.institution.name', 'Masjid API');

    $institution = Institution::query()->where('name', 'Masjid API')->firstOrFail();

    expect($institution->status)->toBe('pending')
        ->and($institution->members()->whereKey($user->id)->exists())->toBeFalse()
        ->and(ContributionRequest::query()->where('entity_id', $institution->getKey())->exists())->toBeTrue();
});

it('creates speaker contribution requests through the frontend api with an explicit country alias', function () {
    $user = User::factory()->create();
    $singaporeId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();

    Sanctum::actingAs($user);

    $this->withHeader('X-Timezone', 'Asia/Singapore')
        ->postJson(route('api.client.contributions.speakers.store'), [
            'name' => 'Frontend API Scoped Country Speaker',
            'gender' => 'male',
            'address' => [
                'country_id' => $singaporeId,
                'state_id' => null,
            ],
        ])->assertCreated()
        ->assertJsonPath('data.speaker.name', 'Frontend API Scoped Country Speaker');

    $speaker = Speaker::query()
        ->with('addresses')
        ->where('name', 'Frontend API Scoped Country Speaker')
        ->firstOrFail();

    expect($speaker->primaryAddress()?->country_id)->toBe($singaporeId)
        ->and($speaker->slug)->toEndWith('-sg')
        ->and(ContributionRequest::query()->where('entity_id', $speaker->getKey())->exists())->toBeTrue();
});

it('requires explicit country and still prohibits detailed address fields when creating speakers through the frontend api', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.contributions.speakers.store'), [
        'name' => 'Frontend API Missing Speaker Country',
        'gender' => 'male',
        'address' => [
            'state_id' => null,
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['address.country_id']);

    $this->postJson(route('api.client.contributions.speakers.store'), [
        'name' => 'Frontend API Invalid Speaker Address',
        'gender' => 'male',
        'address' => [
            'country_id' => ensureFrontendApiMalaysiaCountryExists(),
            'line1' => 'Alamat Lama',
            'google_maps_url' => 'https://maps.google.com/?q=1,1',
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'address.line1',
            'address.google_maps_url',
        ]);
});

it('returns profile-quality speaker avatar urls from the frontend search api', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $speaker = Speaker::factory()->create([
        'name' => 'Kazim Elias',
        'gender' => 'male',
        'status' => 'verified',
    ]);

    $speaker->addMedia(fakeGeneratedImageUpload('kazim.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    $this->getJson(route('api.client.speakers.index', ['search' => 'kazim']))
        ->assertOk()
        ->assertJsonPath('data.0.avatar_url', $speaker->public_avatar_url);
});

it('exposes explicit country data and country filters on frontend institution and speaker reads', function () {
    $countryId = ensureFrontendApiMalaysiaCountryExists();

    $institution = Institution::factory()->create([
        'name' => 'Country Filter Institution',
        'type' => 'masjid',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institution, [
        'country_id' => $countryId,
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Country Filter Speaker',
        'gender' => 'male',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
    ]);

    $this->getJson(route('api.client.institutions.index', ['country_id' => $countryId, 'search' => 'Country Filter Institution']))
        ->assertOk()
        ->assertJsonPath('data.0.country.iso2', 'MY')
        ->assertJsonPath('data.0.country.key', 'malaysia')
        ->assertJsonPath('data.0.type', 'masjid');

    $this->getJson(route('api.client.speakers.index', ['country_id' => $countryId, 'search' => 'Country Filter Speaker']))
        ->assertOk()
        ->assertJsonPath('data.0.country.iso2', 'MY')
        ->assertJsonPath('data.0.country.key', 'malaysia')
        ->assertJsonPath('data.0.gender', 'male');
});

it('returns authenticated follow state in the frontend speaker api', function () {
    $user = User::factory()->create();

    $speaker = Speaker::factory()->create([
        'name' => 'Kazim Follow Speaker',
        'status' => 'verified',
    ]);

    $user->follow($speaker);

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.speakers.index', ['search' => 'Kazim Follow Speaker']))
        ->assertOk()
        ->assertJsonPath('data.0.is_following', true);
});

it('returns the total followed speaker count for the full speaker query, not just the current page', function () {
    $user = User::factory()->create();

    $followedSpeakers = Speaker::factory()->count(3)->create([
        'status' => 'verified',
    ]);

    $unfollowedSpeaker = Speaker::factory()->create([
        'name' => 'Unfollowed Speaker',
        'status' => 'verified',
    ]);

    foreach ($followedSpeakers as $speaker) {
        $user->follow($speaker);
    }

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.speakers.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 4)
        ->assertJsonPath('meta.following.total', 3);
});

it('supports server-side filtering to only followed speakers in the frontend speaker api', function () {
    $user = User::factory()->create();

    $followedSpeaker = Speaker::factory()->create([
        'name' => 'Followed Speaker Only',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Not Followed Speaker',
        'status' => 'verified',
    ]);

    $user->follow($followedSpeaker);

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.speakers.index', ['following' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $followedSpeaker->slug)
        ->assertJsonPath('data.0.is_following', true)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.following.total', 1);
});

it('returns the total followed reference count for the filtered reference query, not just the current page', function () {
    $user = User::factory()->create();

    $followedReferences = Reference::factory()->count(2)->create([
        'status' => 'verified',
        'author' => 'Imam Nawawi',
    ]);

    Reference::factory()->create([
        'title' => 'Unfollowed Reference',
        'author' => 'Imam Nawawi',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Outside Search Scope',
        'author' => 'Ibn Hajar',
        'status' => 'verified',
    ]);

    foreach ($followedReferences as $reference) {
        $user->follow($reference);
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/references?search='.urlencode('Imam Nawawi').'&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.following.total', 2);
});

it('supports server-side filtering to only followed references in the frontend reference api', function () {
    $user = User::factory()->create();

    $followedReference = Reference::factory()->create([
        'title' => 'Followed Reference Only',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Not Followed Reference',
        'status' => 'verified',
    ]);

    $user->follow($followedReference);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/references?following=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $followedReference->slug)
        ->assertJsonPath('data.0.is_following', true)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.following.total', 1);
});

it('falls back to the original front cover url in reference directory serialization when the thumb conversion is not ready', function () {
    $reference = new class extends Reference
    {
        public function __construct()
        {
            parent::__construct();

            $this->forceFill([
                'id' => 'reference-fallback-id',
                'slug' => 'reference-cover-fallback',
                'title' => 'Reference Cover Fallback',
                'parent_id' => null,
                'author' => null,
                'type' => null,
                'publisher' => null,
                'publication_year' => null,
                'status' => 'verified',
                'metadata' => [],
            ]);
            $this->exists = true;
            $this->setAttribute('events_count', 0);
        }

        public function getFirstMediaUrl(string $collectionName = 'default', string $conversionName = ''): string
        {
            if ($collectionName !== 'front_cover') {
                return '';
            }

            if ($conversionName === 'thumb') {
                return '';
            }

            return 'https://cdn.example.test/reference-original.webp';
        }
    };

    $item = Closure::bind(
        fn (): array => $this->referenceListData($reference),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'front_cover_url'))->toBe('https://cdn.example.test/reference-original.webp');
});

it('counts all public linked events on the reference directory cards', function () {
    $reference = Reference::factory()->create([
        'title' => 'Reference Event Count Coverage',
        'status' => 'verified',
    ]);

    $upcomingEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDays(2),
    ]);

    $pastEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->subDays(2),
    ]);

    withGlobalOwnerContext(function () use ($reference, $upcomingEvent, $pastEvent): void {
        $reference->events()->attach($upcomingEvent, ['sort_order' => 1]);
        $reference->events()->attach($pastEvent, ['sort_order' => 2]);
    });

    $this->getJson('/api/v1/references?search='.urlencode('Reference Event Count Coverage'))
        ->assertOk()
        ->assertJsonPath('data.0.events_count', 2);
});

it('does not expose pending references on the public detail route', function () {
    $reference = Reference::factory()->create([
        'title' => 'Pending Public Reference',
        'status' => 'pending',
    ]);

    $this->getJson(route('api.client.references.show', ['referenceKey' => $reference->slug]))
        ->assertNotFound();
});

it('allows authorized users to view pending references on the frontend detail route', function () {
    $reference = Reference::factory()->create([
        'title' => 'Pending Moderator Reference',
        'status' => 'pending',
    ]);

    $moderator = User::factory()->create();

    if (! Role::query()->where('name', 'moderator')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'moderator',
            'guard_name' => 'web',
        ])->save();
    }

    $moderator->assignRole('moderator');

    Sanctum::actingAs($moderator);

    $this->getJson(route('api.client.references.show', ['referenceKey' => $reference->slug]))
        ->assertOk()
        ->assertJsonPath('data.reference.id', (string) $reference->id)
        ->assertJsonPath('data.reference.title', 'Pending Moderator Reference');
});

it('falls back to indexed reference search when typesense fails', function () {
    $matchingReference = Reference::factory()->create([
        'title' => 'Rujukan Fallback',
        'slug' => 'rujukan-fallback-agama',
        'author' => 'Imam Contoh',
        'description' => 'Syarahan tajwid dan adab',
        'status' => 'verified',
    ]);

    $otherReference = Reference::factory()->create([
        'title' => 'Rujukan Lain',
        'status' => 'verified',
    ]);

    config()->set('scout.driver', 'typesense');

    $this->app->bind(ReferenceSearchService::class, fn (): ReferenceSearchService => new class extends ReferenceSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $response = $this->getJson('/api/v1/references?search='.urlencode('tajwid adab'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain((string) $matchingReference->id)
        ->not->toContain((string) $otherReference->id);
});

it('serializes speaker directory payloads with country and follow metadata for mobile clients', function () {
    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $user = User::factory()->create();

    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Directory DTO',
        'gender' => 'male',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
    ]);

    $user->follow($speaker);

    $directorySpeaker = $speaker->fresh();

    expect($directorySpeaker)->not->toBeNull();

    $item = Closure::bind(
        fn (): array => $this->speakerListData($directorySpeaker, $user),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($item, 'formatted_name'))->toBe($speaker->formatted_name)
        ->and(data_get($item, 'gender'))->toBe('male')
        ->and(data_get($item, 'avatar_url'))->toBeString()->not->toBe('')
        ->and(data_get($item, 'status'))->toBe('verified')
        ->and(data_get($item, 'status'))->toBeIn(['verified', 'pending'])
        ->and(data_get($item, 'country.id'))->toBe($countryId)
        ->and(data_get($item, 'country.key'))->toBe('malaysia')
        ->and(data_get($item, 'is_following'))->toBeTrue();
});

it('bumps the speaker directory cache version when speaker records change', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Cache Version',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $speaker->update([
        'job_title' => 'Pensyarah Kanan',
    ]);

    $updatedVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($initialVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->not->toBe($initialVersion);
});

it('bumps the speaker directory cache version when speaker media changes', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Cache Media',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $speaker->addMedia(fakeGeneratedImageUpload('speaker-cache-media.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    $updatedVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->not->toBe($initialVersion);
});

it('bumps the speaker directory cache version when speaker addresses change', function () {
    $countryId = ensureFrontendApiMalaysiaCountryExists();

    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Cache Address',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    syncPrimaryAddressForTest($speaker, [
        'country_id' => $countryId,
        'state_id' => null,
        'admin_area_1_id' => null,
        'admin_area_2_id' => null,
    ]);

    $updatedVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('');
});

it('bumps public directory cache versions when country metadata changes', function () {
    $countryId = ensureFrontendApiMalaysiaCountryExists();

    $institution = Institution::factory()->create([
        'name' => 'Institution Cache Country Metadata',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($institution, ['country_id' => $countryId]);

    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Cache Country Metadata',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($speaker, ['country_id' => $countryId]);

    $initialInstitutionVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');
    $initialSpeakerVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $country = AddressCountry::query()->findOrFail($countryId);
    $country->name = 'Malaysia Baru';
    $country->save();

    $updatedInstitutionVersion = $this->getJson(route('api.client.institutions.index'))
        ->assertOk()
        ->json('meta.cache.version');
    $updatedSpeakerVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedInstitutionVersion)->toBeString()->not->toBe('')
        ->and($updatedInstitutionVersion)->not->toBe($initialInstitutionVersion)
        ->and($updatedSpeakerVersion)->toBeString()->not->toBe('')
        ->and($updatedSpeakerVersion)->not->toBe($initialSpeakerVersion);
});

it('bumps the speaker directory cache version when speaker event participation changes', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Cache Event',
        'status' => 'verified',
    ]);

    $initialVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDays(3)->setTime(19, 0),
    ]);

    EventKeyPersonFactory::new()
        ->for($event, 'event')
        ->for($speaker, 'speaker')
        ->create();

    $updatedVersion = $this->getJson(route('api.client.speakers.index'))
        ->assertOk()
        ->json('meta.cache.version');

    expect($updatedVersion)->toBeString()->not->toBe('')
        ->and($updatedVersion)->not->toBe($initialVersion);
});

it('uses the same stable public directory ordering in the frontend speaker api', function () {
    $firstSpeaker = Speaker::factory()->create([
        'name' => 'Adam Speaker API',
        'status' => 'verified',
    ]);

    $secondSpeaker = Speaker::factory()->create([
        'name' => 'Zaid Speaker API',
        'status' => 'verified',
    ]);

    $response = $this->getJson(route('api.client.speakers.index', [
        'search' => 'Speaker API',
        'per_page' => 12,
    ]))->assertOk();

    $expectedOrder = [$firstSpeaker->id, $secondSpeaker->id];

    usort($expectedOrder, static function (string $left, string $right): int {
        $leftParts = Speaker::publicDirectorySortParts($left);
        $rightParts = Speaker::publicDirectorySortParts($right);

        $primaryComparison = $leftParts['primary'] <=> $rightParts['primary'];

        if ($primaryComparison !== 0) {
            return $primaryComparison;
        }

        return $leftParts['secondary'] <=> $rightParts['secondary'];
    });

    expect(collect($response->json('data'))
        ->pluck('id')
        ->intersect([$firstSpeaker->id, $secondSpeaker->id])
        ->values()
        ->all())->toBe($expectedOrder);
});

it('uses an explicit mobile directory seed for speaker ordering', function () {
    $seedPairs = collect(['mobile-seed-a', 'mobile-seed-b', 'mobile-seed-c', 'mobile-seed-d'])
        ->crossJoin(['mobile-seed-a', 'mobile-seed-b', 'mobile-seed-c', 'mobile-seed-d'])
        ->first(fn (array $pair): bool => $pair[0] !== $pair[1]
            && Speaker::publicDirectoryOrderOffset($pair[0]) !== Speaker::publicDirectoryOrderOffset($pair[1]));

    expect($seedPairs)->not->toBeNull();

    [$firstSeed, $secondSeed] = $seedPairs;

    $firstOffset = Speaker::publicDirectoryOrderOffset($firstSeed);
    $secondOffset = Speaker::publicDirectoryOrderOffset($secondSeed);

    $speakerId = static function (string $firstChar, string $secondChar) use ($firstOffset, $secondOffset): string {
        $characters = array_fill(0, 32, '0');
        $characters[$firstOffset - 1] = $firstChar;
        $characters[$secondOffset - 1] = $secondChar;
        $normalized = implode('', $characters);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($normalized, 0, 8),
            substr($normalized, 8, 4),
            substr($normalized, 12, 4),
            substr($normalized, 16, 4),
            substr($normalized, 20, 12),
        );
    };

    $speakers = collect([
        ['id' => $speakerId('f', '0'), 'name' => 'Speaker Seed A'],
        ['id' => $speakerId('0', 'f'), 'name' => 'Speaker Seed B'],
        ['id' => $speakerId('8', '1'), 'name' => 'Speaker Seed C'],
        ['id' => $speakerId('1', '8'), 'name' => 'Speaker Seed D'],
    ])->map(fn (array $attributes) => Speaker::factory()->create([
        ...$attributes,
        'status' => 'verified',
    ]));

    $firstResponse = $this->getJson(route('api.client.speakers.index', [
        'per_page' => 12,
        'directory_seed' => $firstSeed,
    ]))->assertOk();

    $secondResponse = $this->getJson(route('api.client.speakers.index', [
        'per_page' => 12,
        'directory_seed' => $secondSeed,
    ]))->assertOk();

    $speakerIds = $speakers->pluck('id')->all();
    $expectedFirstOrder = $speakerIds;
    $expectedSecondOrder = $speakerIds;

    usort($expectedFirstOrder, static function (string $left, string $right) use ($firstSeed): int {
        $leftParts = Speaker::publicDirectorySortParts($left, $firstSeed);
        $rightParts = Speaker::publicDirectorySortParts($right, $firstSeed);

        $primaryComparison = $leftParts['primary'] <=> $rightParts['primary'];

        if ($primaryComparison !== 0) {
            return $primaryComparison;
        }

        return $leftParts['secondary'] <=> $rightParts['secondary'];
    });

    usort($expectedSecondOrder, static function (string $left, string $right) use ($secondSeed): int {
        $leftParts = Speaker::publicDirectorySortParts($left, $secondSeed);
        $rightParts = Speaker::publicDirectorySortParts($right, $secondSeed);

        $primaryComparison = $leftParts['primary'] <=> $rightParts['primary'];

        if ($primaryComparison !== 0) {
            return $primaryComparison;
        }

        return $leftParts['secondary'] <=> $rightParts['secondary'];
    });

    expect(collect($firstResponse->json('data'))->pluck('id')->intersect($speakerIds)->values()->all())
        ->toBe($expectedFirstOrder)
        ->and(collect($secondResponse->json('data'))->pluck('id')->intersect($speakerIds)->values()->all())
        ->toBe($expectedSecondOrder)
        ->and($expectedFirstOrder)->not->toBe($expectedSecondOrder);
});

it('returns random inspiration payloads with category and media metadata for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    Inspiration::factory()->category(InspirationCategory::QuranQuote)->create([
        'locale' => 'en',
        'title' => 'English Inspiration',
        'content' => 'English content should be filtered out.',
    ]);

    $inspiration = Inspiration::factory()->category(InspirationCategory::IslamicComic)->create([
        'locale' => 'ms',
        'title' => 'Komik API',
        'content' => 'Renungan komik untuk klien mudah alih.',
        'source' => 'Sirah API',
        'status' => 'active',
    ]);

    $inspiration->addMedia(fakeGeneratedImageUpload('inspiration.jpg', 1200, 900))
        ->toMediaCollection('main');

    $response = $this->getJson(route('api.client.inspirations.random', ['locale' => 'ms']))
        ->assertOk();

    expect($response->json('data.id'))->toBe($inspiration->id)
        ->and($response->json('data.title'))->toBe('Komik API')
        ->and($response->json('data.content'))->toContain('Renungan komik untuk klien mudah alih')
        ->and($response->json('data.content_html'))->toContain('Renungan komik untuk klien mudah alih')
        ->and($response->json('data.preview_text'))->not->toBe('')
        ->and($response->json('data.source'))->toBe('Sirah API')
        ->and($response->json('data.category.value'))->toBe(InspirationCategory::IslamicComic->value)
        ->and($response->json('data.category.label'))->toBe(InspirationCategory::IslamicComic->label())
        ->and($response->json('data.category.color'))->toBe(InspirationCategory::IslamicComic->color())
        ->and($response->json('data.category.is_comic'))->toBeTrue()
        ->and($response->json('data.media.has_media'))->toBeTrue()
        ->and($response->json('data.media.thumb_url'))->not->toBe('')
        ->and($response->json('data.media.full_url'))->not->toBe('')
        ->and($response->json('meta.locale'))->toBe('ms');
});

it('submits and cancels membership claims through the frontend api', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($user);

    $storeResponse = $this->post(route('api.client.membership-applications.store', [
        'subjectType' => 'institusi',
        'subject' => $institution->getKey(),
    ]), [
        'justification' => 'I manage this institution.',
        'evidence' => [fakeGeneratedImageUpload('evidence.jpg')],
    ], [
        'Accept' => 'application/json',
    ])->assertCreated();

    $claimId = $storeResponse->json('data.application.id');

    expect(withGlobalOwnerContext(fn (): bool => MembershipApplication::query()->whereKey($claimId)->exists()))->toBeTrue();

    $this->deleteJson(route('api.client.membership-applications.cancel', ['applicationId' => $claimId]))
        ->assertOk()
        ->assertJsonPath('data.application.status', 'cancelled');
});

it('enforces institution workspace member management permissions', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    $newMember = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $viewer, 'viewer');

    Sanctum::actingAs($viewer);

    $this->postJson(route('api.client.institution-workspace.members.store', ['institutionId' => $institution->getKey()]), [
        'email' => $newMember->email,
        'role_id' => 'viewer',
    ])->assertForbidden();

    Sanctum::actingAs($admin);

    $this->postJson(route('api.client.institution-workspace.members.store', ['institutionId' => $institution->getKey()]), [
        'email' => $newMember->email,
        'role_id' => 'viewer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.member.email', $newMember->email);

    expect($institution->members()->whereKey($newMember->getKey())->exists())->toBeTrue();
});

it('rejects invalid and protected institution member roles', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $newMember = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    Sanctum::actingAs($admin);

    $route = route('api.client.institution-workspace.members.store', ['institutionId' => $institution->getKey()]);

    $this->postJson($route, [
        'email' => $newMember->email,
        'role_id' => 'not-a-role',
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);

    $this->postJson($route, [
        'email' => $newMember->email,
        'role_id' => 'owner',
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
});

it('forbids institution member management over bearer tokens for viewers even with token abilities', function () {
    $institution = Institution::factory()->create();
    $viewer = User::factory()->create();
    $newMember = User::factory()->create();

    addTestMember($institution, $viewer, 'viewer');

    $viewerToken = $viewer->createToken('viewer-device', ['institution.manage-members'])->plainTextToken;

    $this->withToken($viewerToken)
        ->postJson(route('api.client.institution-workspace.members.store', ['institutionId' => $institution->getKey()]), [
            'email' => $newMember->email,
            'role_id' => 'viewer',
        ])->assertForbidden();
});

it('allows institution member management over bearer tokens for admins without token abilities', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $newMember = User::factory()->create();

    addTestMember($institution, $admin, 'admin');

    $adminToken = $admin->createToken('admin-device', [])->plainTextToken;

    $this->withToken($adminToken)
        ->postJson(route('api.client.institution-workspace.members.store', ['institutionId' => $institution->getKey()]), [
            'email' => $newMember->email,
            'role_id' => 'viewer',
        ])
        ->assertCreated()
        ->assertJsonPath('data.member.email', $newMember->email);

    expect($institution->members()->whereKey($newMember->getKey())->exists())->toBeTrue();
});

it('scopes the spaces catalog to global spaces unless an institution is selected', function () {
    $institution = Institution::factory()->create();
    $otherInstitution = Institution::factory()->create();

    $globalSpace = Space::factory()->create([
        'name' => 'Global Space Catalog',
        'status' => 'active',
    ]);
    $institutionSpace = Space::factory()->create([
        'name' => 'Institution Space Catalog',
        'status' => 'active',
    ]);
    $otherInstitutionSpace = Space::factory()->create([
        'name' => 'Other Institution Space Catalog',
        'status' => 'active',
    ]);

    $institutionSpace->institutions()->attach($institution);
    $otherInstitutionSpace->institutions()->attach($otherInstitution);

    $globalResponse = $this->getJson(route('api.client.catalogs.spaces'))
        ->assertOk()
        ->json('data');

    expect(collect($globalResponse)->pluck('id')->all())
        ->toContain((string) $globalSpace->getKey())
        ->not->toContain((string) $institutionSpace->getKey())
        ->not->toContain((string) $otherInstitutionSpace->getKey());

    $scopedResponse = $this->getJson(route('api.client.catalogs.spaces', [
        'institution_id' => $institution->getKey(),
    ]))
        ->assertOk()
        ->json('data');

    expect(collect($scopedResponse)->pluck('id')->all())
        ->toContain((string) $globalSpace->getKey())
        ->toContain((string) $institutionSpace->getKey())
        ->not->toContain((string) $otherInstitutionSpace->getKey());
});

it('keeps public catalog selectors label-based', function () {
    ensureFrontendApiMalaysiaCountryExists();

    $space = Space::factory()->create([
        'name' => 'Label Contract Space',
        'status' => 'active',
    ]);

    $countries = $this->getJson(route('api.client.catalogs.countries'))
        ->assertOk()
        ->json('data');

    $spaces = $this->getJson(route('api.client.catalogs.spaces'))
        ->assertOk()
        ->json('data');

    $malaysia = collect($countries)->firstWhere('key', 'malaysia');
    $spacePayload = collect($spaces)->firstWhere('id', (string) $space->getKey());

    expect($malaysia)->not->toBeNull()
        ->and(array_keys($malaysia))->toBe(['id', 'label', 'iso2', 'key'])
        ->and($malaysia['label'])->toBe('Malaysia')
        ->and($spacePayload)->not->toBeNull()
        ->and(array_keys($spacePayload))->toBe(['id', 'label'])
        ->and($spacePayload['label'])->toBe('Label Contract Space');
});

it('returns all matching spaces without truncating the catalog payload', function () {
    $spaces = collect(range(1, 105))->map(fn (int $index): Space => Space::factory()->create([
        'name' => sprintf('Catalog Overflow Space %03d', $index),
        'status' => 'active',
    ]));

    $response = $this->getJson(route('api.client.catalogs.spaces'))
        ->assertOk()
        ->json('data');

    $responseIds = collect($response)->pluck('id')->all();

    expect($spaces->map(fn (Space $space): string => (string) $space->getKey())->diff($responseIds)->all())
        ->toBe([]);
});

it('returns the institution workspace payload for the selected accessible institution', function () {
    $institution = Institution::factory()->create(['name' => 'Workspace Institution']);
    $secondInstitution = Institution::factory()->create(['name' => 'Second Workspace Institution']);
    $admin = User::factory()->create();
    $viewer = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $viewer, 'viewer');
    addTestMember($secondInstitution, $admin, 'admin');

    Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Workspace Event',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'starts_at' => now()->addDay(),
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.client.institution-workspace.show', [
        'institution_id' => $institution->getKey(),
    ]))
        ->assertOk();

    expect($response->json('data.selected_institution.id'))->toBe($institution->getKey())
        ->and($response->json('data.events.0.title'))->toBe('Workspace Event')
        ->and($response->json('data.members.0.email'))->not->toBe('')
        ->and($response->json('data.can_manage_members'))->toBeTrue()
        ->and($response->json('meta.members_pagination.total'))->toBe(2);
});

it('submits events with media through the frontend api', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->post(route('api.client.submit-event.store'), [
        'title' => 'Frontend API Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'speakers' => [$speaker->getKey()],
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
        'cover' => fakeGeneratedImageUpload('cover.jpg', 1600, 900),
        'poster' => fakeGeneratedImageUpload('poster.jpg', 1200, 1500),
        'gallery' => [fakeGeneratedImageUpload('gallery.jpg')],
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Frontend API Event');

    $event = withGlobalOwnerContext(fn () => Event::query()->where('title', 'Frontend API Event')->firstOrFail());

    expect($event->getMedia('cover'))->toHaveCount(1)
        ->and($event->getMedia('poster'))->toHaveCount(1)
        ->and($event->getMedia('gallery'))->toHaveCount(1);
});

it('rejects frontend event submission cover and poster uploads with invalid aspect ratios', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    $this->post(route('api.client.submit-event.store'), [
        'title' => 'Invalid Ratio API Event',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'primary_organizer_id' => $institution->getKey(),
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
        'submitter_name' => 'Guest User',
        'submitter_email' => 'guest@example.com',
        'cover' => fakeGeneratedImageUpload('cover-invalid.jpg', 1200, 800),
        'poster' => fakeGeneratedImageUpload('poster-invalid.jpg', 1600, 900),
    ], [
        'Accept' => 'application/json',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cover', 'poster']);
});

it('requires explicit country input for frontend event submissions and accepts aliases', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    Sanctum::actingAs($user);

    $payload = [
        'title' => 'Frontend API Legacy Timezone Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'lain_waktu',
        'custom_time' => '20:15',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'speakers' => [$speaker->getKey()],
    ];

    $this->postJson(route('api.client.submit-event.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submission_country_id']);

    $this->postJson(route('api.client.submit-event.store'), array_merge($payload, [
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Frontend API Legacy Timezone Event');

    $event = withGlobalOwnerContext(fn () => Event::query()->where('title', 'Frontend API Legacy Timezone Event')->firstOrFail());

    expect($event->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($event->starts_at?->timezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('20:15');
});

it('accepts any valid submission country uuid for frontend event submissions', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    $disabledCountryId = (string) ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey();

    $payload = [
        'title' => 'Frontend API Invalid Country Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'speakers' => [$speaker->getKey()],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.test',
    ];

    $this->postJson(route('api.client.submit-event.store'), array_merge($payload, [
        'submission_country_id' => 'not-a-uuid',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submission_country_id']);

    $this->postJson(route('api.client.submit-event.store'), array_merge($payload, [
        'submission_country_id' => $disabledCountryId,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Frontend API Invalid Country Event');
});

it('requires guest event submissions to include email or phone', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    $this->postJson(route('api.client.submit-event.store'), [
        'title' => 'Guest Frontend API Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'speakers' => [$speaker->getKey()],
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
        'submitter_name' => 'Guest Submitter',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['submitter_email', 'submitter_phone']);
});

it('allows online frontend event submissions without a live url', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();

    $this->postJson(route('api.client.submit-event.store'), [
        'title' => 'Online Frontend API Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'online',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $institution->getKey(),
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.test',
    ])
        ->assertCreated()
        ->assertJsonPath('data.event.title', 'Online Frontend API Event');

    expect(withGlobalOwnerContext(fn () => Event::query()->where('title', 'Online Frontend API Event')->firstOrFail()->live_url))->toBeNull();
});

it('requires a physical location for speaker-organized physical event submissions', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();

    $this->postJson(route('api.client.submit-event.store'), [
        'title' => 'Speaker Physical Event',
        'description' => 'API description',
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDay()->toDateString(),
        'prayer_time' => 'selepas_maghrib',
        'event_format' => 'physical',
        'visibility' => 'public',
        'gender' => 'all',
        'age_group' => ['all_ages'],
        'languages' => [101],
        'domain_tags' => [$domainTag->getKey()],
        'primary_organizer_id' => $speaker->getKey(),
        'submission_country_id' => ensureFrontendApiMalaysiaCountryExists(),
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.test',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['location_type']);
});

it('mirrors public detail media and public contact payloads', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $speaker->contactMethods()->create([
        'type' => ContactMethodType::Email->value,
        'purpose' => ContactPurpose::General->value,
        'value' => 'public-speaker@example.test',
        'is_public' => true,
    ]);
    $speaker->contactMethods()->create([
        'type' => ContactMethodType::Phone->value,
        'purpose' => ContactPurpose::General->value,
        'value' => '+6011222333',
        'is_public' => false,
    ]);
    $speaker->socialProfiles()->create([
        'platform' => SocialPlatform::Website->value,
        'purpose' => ContactPurpose::General->value,
        'url' => 'https://speaker.example.test',
    ]);
    $speaker->addMedia(fakeGeneratedImageUpload('speaker-cover.jpg'))->toMediaCollection('cover');

    $venue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $venue->contactMethods()->create([
        'type' => ContactMethodType::Phone->value,
        'purpose' => ContactPurpose::General->value,
        'value' => '+60312345678',
        'is_public' => true,
    ]);
    $venue->addMedia(fakeGeneratedImageUpload('venue-cover.jpg'))->toMediaCollection('cover');

    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);
    $reference->socialProfiles()->create([
        'platform' => SocialPlatform::Website->value,
        'purpose' => ContactPurpose::General->value,
        'url' => 'https://reference.example.test',
    ]);
    $reference->addMedia(fakeGeneratedImageUpload('reference-front-cover.jpg'))->toMediaCollection('front_cover');

    $speakerResponse = $this->getJson(route('api.client.speakers.show', ['speakerKey' => $speaker->slug]))
        ->assertOk();
    $venueResponse = $this->getJson(route('api.client.venues.show', ['venueKey' => $venue->slug]))
        ->assertOk();
    $referenceResponse = $this->getJson(route('api.client.references.show', ['referenceKey' => $reference->slug]))
        ->assertOk();

    $speakerContactValues = collect($speakerResponse->json('data.speaker.contacts'))->pluck('value')->all();

    expect($speakerResponse->json('data.speaker.media.cover_url'))->not->toBeEmpty()
        ->and($speakerContactValues)->toContain('public-speaker@example.test')
        ->and($speakerContactValues)->not->toContain('+6011222333')
        ->and($speakerResponse->json('data.speaker.social_media.0.resolved_url'))->toBe('https://speaker.example.test')
        ->and($venueResponse->json('data.venue.media.cover_url'))->not->toBeEmpty()
        ->and($referenceResponse->json('data.reference.media.front_cover_url'))->not->toBeEmpty()
        ->and($referenceResponse->json('data.reference.social_media.0.resolved_url'))->toBe('https://reference.example.test');

    $speakerResponse->assertJsonMissingPath('data.speaker.media.main_url');
    $referenceResponse->assertJsonMissingPath('data.reference.media.cover_url');
});

it('serializes venue and reference detail payloads with core metadata for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();

    $venue = Venue::factory()->create([
        'name' => 'Dewan DTO API',
        'description' => 'Venue detail serializer coverage',
        'status' => 'verified',
    ]);
    $venue->contactMethods()->create([
        'type' => ContactMethodType::Phone->value,
        'purpose' => ContactPurpose::General->value,
        'value' => '+60399887766',
        'is_public' => true,
    ]);
    $venue->socialProfiles()->create([
        'platform' => SocialPlatform::Website->value,
        'purpose' => ContactPurpose::General->value,
        'url' => 'https://venue.example.test',
    ]);
    $venue->addMedia(fakeGeneratedImageUpload('venue-dto-cover.jpg', 1600, 900))->toMediaCollection('cover');

    $reference = Reference::factory()->create([
        'title' => 'Rujukan DTO API',
        'author' => 'Penulis API',
        'type' => 'book',
        'publisher' => 'Penerbit API',
        'publication_year' => 2024,
        'description' => 'Reference detail serializer coverage',
        'status' => 'verified',
    ]);
    $reference->socialProfiles()->create([
        'platform' => SocialPlatform::Website->value,
        'purpose' => ContactPurpose::General->value,
        'url' => 'https://reference-dto.example.test',
    ]);
    $reference->addMedia(fakeGeneratedImageUpload('reference-front.jpg', 1200, 1600))->toMediaCollection('front_cover');
    $reference->addMedia(fakeGeneratedImageUpload('reference-back.jpg', 1200, 1600))->toMediaCollection('back_cover');

    $user->follow($reference);

    $detailVenue = $venue->fresh(['media', 'contactMethods', 'socialProfiles']);
    $detailReference = $reference->fresh(['media', 'socialProfiles']);

    expect($detailVenue)->not->toBeNull()
        ->and($detailReference)->not->toBeNull();

    $venueItem = Closure::bind(
        fn (): array => $this->venueDetailData($detailVenue),
        app(SearchController::class),
        SearchController::class,
    )();

    $referenceItem = Closure::bind(
        fn (): array => $this->referenceDetailData($detailReference, $user),
        app(SearchController::class),
        SearchController::class,
    )();

    expect(data_get($venueItem, 'name'))->toBe('Dewan DTO API')
        ->and(data_get($venueItem, 'status'))->toBe('verified')
        ->and(data_get($venueItem, 'status'))->toBeIn(['verified', 'pending'])
        ->and(data_get($venueItem, 'media.cover_url'))->toBeString()->not->toBe('')
        ->and(data_get($venueItem, 'contacts.0.value'))->toBe('+60399887766')
        ->and(data_get($venueItem, 'social_media.0.resolved_url'))->toBe('https://venue.example.test')
        ->and(data_get($referenceItem, 'title'))->toBe('Rujukan DTO API')
        ->and(data_get($referenceItem, 'author'))->toBe('Penulis API')
        ->and(data_get($referenceItem, 'publication_year'))->toBe('2024')
        ->and(data_get($referenceItem, 'is_following'))->toBeTrue()
        ->and(data_get($referenceItem, 'media.front_cover_url'))->toBeString()->not->toBe('')
        ->and(data_get($referenceItem, 'media.back_cover_url'))->toBeString()->not->toBe('')
        ->and(data_get($referenceItem, 'social_media.0.resolved_url'))->toBe('https://reference-dto.example.test');
});

it('serializes series detail payloads with follow and media metadata for mobile clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();

    $series = Series::factory()->create([
        'title' => 'Siri DTO API',
        'description' => 'Series detail serializer coverage',
        'visibility' => 'public',
        'status' => 'active',
    ]);

    $series->addMedia(fakeGeneratedImageUpload('series-cover.jpg', 1600, 900))->toMediaCollection('cover');

    $user->follow($series);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.series.show', ['series' => $series->slug]))
        ->assertOk();

    expect($response->json('data.series.title'))->toBe('Siri DTO API')
        ->and($response->json('data.series.visibility'))->toBe('public')
        ->and($response->json('data.series.is_following'))->toBeTrue()
        ->and($response->json('data.series.media.cover_url'))->toBeString()->not->toBe('')
        ->and($response->json('data.upcoming_total'))->toBe(0)
        ->and($response->json('data.past_total'))->toBe(0);
});

it('mirrors the public speaker page payload for app clients', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $countryId = ensureFrontendApiMalaysiaCountryExists();
    $bioText = str_repeat('Biodata speaker aplikasi ini panjang dan terperinci. ', 20);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'job_title' => 'Penasihat Dakwah',
        'is_freelance' => true,
        'gender' => 'male',
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $bioText,
                ]],
            ]],
        ],
    ]);
    $speaker->addMedia(fakeGeneratedImageUpload('speaker-avatar.jpg'))->toMediaCollection('avatar');
    $speaker->addMedia(fakeGeneratedImageUpload('speaker-cover.jpg'))->toMediaCollection('cover');
    $speaker->addMedia(fakeGeneratedImageUpload('speaker-gallery-1.jpg'))->toMediaCollection('gallery');
    $speaker->addMedia(fakeGeneratedImageUpload('speaker-gallery-2.jpg'))->toMediaCollection('gallery');
    $speaker->update(['job_title' => 'Penasihat Dakwah']);

    $country = AddressCountry::query()->findOrFail($countryId);
    $speakerGeo = createTestPackageGeography('Pahang', 'Temerloh', 'Temerloh', country: $country);

    syncPrimaryAddressForTest($speaker, $speakerGeo['address']);

    $institution = Institution::factory()->create([
        'name' => 'Madrasah API',
        'status' => 'verified',
    ]);
    $institution->addMedia(fakeGeneratedImageUpload('institution-cover.jpg'))->toMediaCollection('cover');
    $speaker->institutions()->attach($institution->id, [
        'position' => 'Mudarris',
        'is_primary' => true,
    ]);

    $venue = Venue::factory()->create([
        'name' => 'Dewan Seri API',
        'status' => 'verified',
    ]);
    $venueSubdistrict = createTestAddressArea('Mentakab', 3, parent: $speakerGeo['district'], country: $country);
    syncPrimaryAddressForTest($venue, [
        ...$speakerGeo['address'],
        'admin_area_2_id' => (string) $venueSubdistrict->getKey(),
        'city' => 'Mentakab',
    ]);

    $bookReference = Reference::factory()->create([
        'title' => 'Kitab API',
        'type' => 'book',
        'status' => 'verified',
    ]);

    $upcomingEvent = Event::factory()->prayerRelative()->create([
        'title' => 'Majlis API Akan Datang',
        'status' => 'pending',
        'visibility' => 'public',
        'delivery_mode' => 'hybrid',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
        'starts_at' => now()->addDays(3)->setTime(19, 30),
        'ends_at' => now()->addDays(3)->setTime(21, 0),
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
    ]);
    $upcomingEvent->eventReferences()->create([
        'referenceable_type' => 'reference',
        'referenceable_id' => $bookReference->id,
        'reference_type' => 'book',
        'visibility' => 'public',
        'sort_order' => 1,
    ]);
    EventKeyPersonFactory::new()->create([
        'event_id' => $upcomingEvent->id,
        'involveable_id' => $speaker->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
    ]);

    $pastEvent = Event::factory()->create([
        'title' => 'Majlis API Lepas',
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
        'starts_at' => now()->subDays(2)->setTime(20, 0),
        'ends_at' => now()->subDays(2)->setTime(22, 0),
        'event_category_ids' => [eventCategoryId('forum')],
    ]);
    EventKeyPersonFactory::new()->create([
        'event_id' => $pastEvent->id,
        'involveable_id' => $speaker->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
    ]);

    $otherRoleEvent = Event::factory()->create([
        'title' => 'Forum API Moderator',
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
        'starts_at' => now()->addWeek()->setTime(20, 0),
        'ends_at' => now()->addWeek()->setTime(22, 0),
        'event_category_ids' => [eventCategoryId('forum')],
    ]);

    EventKeyPerson::factory()->create([
        'event_id' => $otherRoleEvent->id,
        'involveable_type' => 'speaker',
        'involveable_id' => $speaker->id,
        'role_code' => EventKeyPersonRole::Moderator->value,
        'visibility' => 'public',
        'sort_order' => 1,
    ]);

    $response = $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->getJson(route('api.client.speakers.show', ['speakerKey' => $speaker->slug]))
        ->assertOk();

    $speakerInstitution = $response->json('data.speaker.institutions.0');

    expect($response->json('data.speaker.job_title'))->toBe('Penasihat Dakwah')
        ->and($response->json('data.speaker.gender'))->toBe('male')
        ->and($response->json('data.speaker.is_freelance'))->toBeTrue()
        ->and($response->json('data.speaker.address.country_id'))->toBe($countryId)
        ->and($response->json('data.speaker.country.iso2'))->toBe('MY')
        ->and($response->json('data.speaker.country.key'))->toBe('malaysia')
        ->and($response->json('data.speaker.location'))->toBe('Temerloh, Pahang')
        ->and(data_get($response->json('data.speaker.bio'), 'content.0.content.0.text'))->toBe($bioText)
        ->and($response->json('data.speaker.media.avatar_url'))->not->toBeEmpty()
        ->and($response->json('data.speaker.media.share_image_url'))->not->toBeEmpty()
        ->and($response->json('data.speaker.gallery'))->toHaveCount(2)
        ->and($response->json('data.speaker.institutions.0.name'))->toBe('Madrasah API')
        ->and($response->json('data.speaker.institutions.0.position'))->toBe('Mudarris')
        ->and($response->json('data.speaker.institutions.0.is_primary'))->toBeTrue()
        ->and($response->json('data.speaker.institutions.0.public_image_url'))->not->toBeEmpty()
        ->and(array_key_exists('chip_image_url', is_array($speakerInstitution) ? $speakerInstitution : []))->toBeFalse()
        ->and($response->json('data.upcoming_events.0.reference_study_subtitle'))->toBe('Kitab API')
        ->and($response->json('data.upcoming_events.0.event_categories.0.name'))->toBe('Kuliah / Ceramah')
        ->and($response->json('data.upcoming_events.0.event_format'))->toBe('hybrid')
        ->and($response->json('data.upcoming_events.0.event_format_label'))->toBe(EventFormat::Hybrid->getLabel())
        ->and($response->json('data.upcoming_events.0.timing_display'))->not->toBeEmpty()
        ->and($response->json('data.upcoming_events.0.location'))->toBe('Dewan Seri API, Mentakab, Temerloh, Pahang')
        ->and($response->json('data.upcoming_events.0.is_remote'))->toBeTrue()
        ->and($response->json('data.upcoming_events.0.is_pending'))->toBeTrue()
        ->and($response->json('data.upcoming_events.0.is_cancelled'))->toBeFalse()
        ->and($response->json('data.upcoming_events.0.institution.public_image_url'))->toBe($institution->public_image_url)
        ->and(count($response->json('data.upcoming_events')))->toBe(1)
        ->and($response->json('data.upcoming_total'))->toBe(1)
        ->and(count($response->json('data.past_events')))->toBe(1)
        ->and($response->json('data.past_total'))->toBe(1)
        ->and(count($response->json('data.other_role_upcoming_participations')))->toBe(1)
        ->and($response->json('data.other_role_upcoming_total'))->toBe(1)
        ->and(count($response->json('data.other_role_past_participations')))->toBe(0)
        ->and($response->json('data.other_role_past_total'))->toBe(0)
        ->and($response->json('data.other_role_upcoming_participations.0.role'))->toBe('moderator')
        ->and($response->json('data.other_role_upcoming_participations.0.role_label'))->toBe(EventKeyPersonRole::Moderator->getLabel())
        ->and($response->json('data.other_role_upcoming_participations.0.event.title'))->toBe('Forum API Moderator');

    $response->assertJsonMissingPath('data.speaker.bio_html')
        ->assertJsonMissingPath('data.speaker.bio_text')
        ->assertJsonMissingPath('data.speaker.bio_excerpt')
        ->assertJsonMissingPath('data.speaker.should_collapse_bio');
});

it('allows following and unfollowing a speaker through the frontend api', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($user);

    $this->postJson(route('api.client.follows.store', ['type' => 'speaker', 'subject' => $speaker->slug]))
        ->assertCreated()
        ->assertJsonPath('data.is_following', true);

    expect($user->fresh()->isFollowing($speaker))->toBeTrue();

    $this->deleteJson(route('api.client.follows.destroy', ['type' => 'speaker', 'subject' => $speaker->slug]))
        ->assertOk()
        ->assertJsonPath('data.is_following', false);

    expect($user->fresh()->isFollowing($speaker))->toBeFalse();
});
