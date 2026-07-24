<?php

use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\CommerceSupport\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Moderation\Enums\ModerationActionType;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventAgeGroup;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Series;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Services\Signals\SignalsTracker;
use App\Support\Search\PersonSearchService;
use Database\Seeders\ScopedMemberRolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Nnjeim\World\Models\Language;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    foreach (['parking', 'oku', 'women_section', 'ablution_area'] as $code) {
        FacilityType::factory()->create(['code' => $code, 'name' => Str::headline($code), 'is_active' => true]);
    }
});

it('rejects users without admin panel access from the admin api manifest', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/admin/manifest')
        ->assertForbidden();
});

it('lists accessible admin resources for privileged users', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/manifest')
        ->assertOk();

    $documentationLibrary = collect($response->json('data.docs.library') ?? []);

    expect($response->json('data.version'))->toBe('2026-04-21')
        ->and($response->json('data.docs.ui'))->toBe('https://api.ilmu360.test/docs')
        ->and($response->json('data.docs.openapi'))->toBe('https://api.ilmu360.test/docs.json')
        ->and($response->json('data.docs.catalog_endpoint'))->toContain('/api/v1/documentation')
        ->and($response->json('data.docs.document_endpoint_template'))->toContain('/api/v1/documentation/documentId')
        ->and($documentationLibrary->pluck('id')->all())->toContain('docs-admin-mcp-guide', 'docs-general-mcp-guide', 'docs-technical-documentation')
        ->and($response->json('data.surface_sync.strategy'))->toBe('curated_parity')
        ->and($response->json('data.surface_sync.default_panel_only_operations'))->toContain('delete', 'restore', 'replicate', 'reorder')
        ->and($response->json('data.surface_sync.workflow_first_capabilities'))->toContain('event moderation', 'contribution review')
        ->and($response->json('data.workflow_actions.moderate_event.mcp_schema_tool'))->toBe('admin-get-event-moderation-schema')
        ->and($response->json('data.workflow_actions.moderate_event.mcp_tool'))->toBe('admin-moderate-event')
        ->and($response->json('data.workflow_actions.triage_report.mcp_schema_tool'))->toBe('admin-get-report-triage-schema')
        ->and($response->json('data.workflow_actions.triage_report.mcp_tool'))->toBe('admin-triage-report')
        ->and($response->json('data.workflow_actions.review_contribution_request.mcp_schema_tool'))->toBe('admin-get-contribution-request-review-schema')
        ->and($response->json('data.workflow_actions.review_contribution_request.mcp_tool'))->toBe('admin-review-contribution-request')
        ->and($response->json('data.workflow_actions.review_membership_claim.mcp_schema_tool'))->toBe('admin-get-membership-claim-review-schema')
        ->and($response->json('data.workflow_actions.review_membership_claim.mcp_tool'))->toBe('admin-review-membership-claim')
        ->and($response->json('data.write_workflow.discover_resources'))->toContain('/api/v1/admin/manifest')
        ->and($response->json('data.rules'))->toContain('Use the admin record route_key returned by admin collection or detail payloads for record-specific paths.');

    $resourceKeys = collect($response->json('data.resources'))->pluck('key')->all();

    expect($resourceKeys)->toContain('people', 'events', 'inspirations', 'institutions', 'references', 'reports', 'series', 'spaces', 'venues', 'donation-channels', 'address-countries', 'address-areas');
});

it('allows viewer-role users who can access the admin panel to reach the admin api manifest', function () {
    $viewer = adminApiUser('viewer');

    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/admin/manifest')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'resources' => [
                    ['key'],
                ],
            ],
        ]);
});

it('does not elevate admin manifest access from bearer token abilities alone', function () {
    $nonAdmin = User::factory()->create();
    $nonAdminToken = $nonAdmin->createToken('non-admin-device', ['admin.manifest'])->plainTextToken;

    $this->withToken($nonAdminToken)
        ->getJson('/api/v1/admin/manifest')
        ->assertForbidden();
});

it('uses the authenticated token user roles for admin manifest access without token abilities', function () {
    $viewer = adminApiUser('viewer');
    $viewerToken = $viewer->createToken('viewer-device', [])->plainTextToken;

    $this->withToken($viewerToken)
        ->getJson('/api/v1/admin/manifest')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'resources' => [
                    ['key'],
                ],
            ],
        ]);
});

it('reflects global admin role grants and removals on an existing bearer token', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', 'viewer')->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'viewer',
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $token = $user->createToken('role-drift-check', [])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/manifest')
        ->assertForbidden();

    $user->assignRole('viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->withToken($token)
        ->getJson('/api/v1/admin/manifest')
        ->assertOk();

    $user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->withToken($token)
        ->getJson('/api/v1/admin/manifest')
        ->assertForbidden();
});

it('returns admin speaker resource metadata and records', function () {
    $admin = adminApiUser('super_admin');
    $person = Person::factory()->create([
        'name' => 'Admin API Person',
    ]);
    $personRouteKey = (string) $person->getRouteKey();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/people/meta')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'people')
        ->assertJsonPath('data.resource.pages.index', true)
        ->assertJsonPath('data.resource.abilities.view_any', true)
        ->assertJsonPath('data.resource.write_support.schema', true)
        ->assertJsonPath('data.resource.api_routes.collection', '/api/v1/admin/people')
        ->assertJsonPath('data.resource.api_routes.schema', '/api/v1/admin/people/schema')
        ->assertJsonPath('data.resource.filters.0.key', 'status')
        ->assertJsonPath('data.resource.filters.0.options.verified', 'Verified')
        ->assertJsonPath('data.resource.filters.1.key', 'has_events')
        ->assertJsonPath('data.resource.mcp_tools.get_record_actions.tool', 'admin-get-record-actions')
        ->assertJsonPath('data.resource.mcp_tools.create.arguments.validate_only', false)
        ->assertJsonPath('data.resource.mcp_tools.update.arguments.validate_only', false);

    $this->getJson('/api/v1/admin/people?search=Admin%20API%20Person')
        ->assertOk()
        ->assertJsonPath('data.0.id', $person->getKey())
        ->assertJsonPath('data.0.title', 'Admin API Person')
        ->assertJsonPath('data.0.abilities.view', true);

    $this->getJson('/api/v1/admin/people/'.$personRouteKey)
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'people')
        ->assertJsonPath('data.record.route_key', $personRouteKey)
        ->assertJsonPath('data.record.attributes.name', 'Admin API Person')
        ->assertJsonPath('data.record.abilities.view', true);
});

it('redacts tracked property write keys in admin api payloads', function () {
    $admin = adminApiUser('super_admin');
    $trackedProperty = app(SignalsTracker::class)->defaultTrackedProperty();

    expect($trackedProperty)->not->toBeNull();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/tracked-properties?search='.urlencode((string) $trackedProperty?->name))
        ->assertOk()
        ->assertJsonPath('meta.resource.key', 'tracked-properties')
        ->assertJsonPath('data.0.id', (string) $trackedProperty?->getKey())
        ->assertJsonPath('data.0.attributes.write_key', 'present');

    $this->getJson('/api/v1/admin/tracked-properties/'.$trackedProperty?->getRouteKey())
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'tracked-properties')
        ->assertJsonPath('data.record.route_key', (string) $trackedProperty?->getRouteKey())
        ->assertJsonPath('data.record.attributes.write_key', 'present');
});

it('filters admin speaker records by explicit query parameters', function () {
    $admin = adminApiUser('super_admin');

    $personWithEvents = Person::factory()->create([
        'name' => 'Alpha Verified Person',
        'status' => 'verified',
    ]);

    $personWithoutEvents = Person::factory()->create([
        'name' => 'Beta Inactive Person',
        'status' => 'inactive',
    ]);

    $pendingPerson = Person::factory()->create([
        'name' => 'Gamma Pending Person',
        'status' => 'pending',
    ]);

    Event::factory()->create([
        'title' => 'Person Filter Event',
    ])->persons()->attach($personWithEvents->getKey());

    Sanctum::actingAs($admin);

    $verifiedResponse = $this->getJson('/api/v1/admin/people?filter[status]=verified')
        ->assertOk();

    $verifiedIds = collect($verifiedResponse->json('data'))->pluck('id')->all();

    expect(in_array($personWithEvents->getKey(), $verifiedIds, true))->toBeTrue();
    expect(in_array($personWithoutEvents->getKey(), $verifiedIds, true))->toBeFalse();
    expect(in_array($pendingPerson->getKey(), $verifiedIds, true))->toBeFalse();

    $inactiveResponse = $this->getJson('/api/v1/admin/people?filter[status]=inactive')
        ->assertOk();

    $inactiveIds = collect($inactiveResponse->json('data'))->pluck('id')->all();

    expect(in_array($personWithoutEvents->getKey(), $inactiveIds, true))->toBeTrue();

    $hasEventsResponse = $this->getJson('/api/v1/admin/people?filter[has_events]=true')
        ->assertOk();

    $hasEventsIds = collect($hasEventsResponse->json('data'))->pluck('id')->all();

    expect(in_array($personWithEvents->getKey(), $hasEventsIds, true))->toBeTrue();
    expect(in_array($personWithoutEvents->getKey(), $hasEventsIds, true))->toBeFalse();
});

it('uses the richer speaker institution and reference search behavior on the admin api', function () {
    $admin = adminApiUser('super_admin');

    $matchingPerson = Person::factory()->create([
        'name' => 'Admin API Decorated Person',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
    ]);
    $otherPerson = Person::factory()->create([
        'name' => 'Admin API Other Person',
        'status' => 'verified',
    ]);

    app(PersonSearchService::class)->syncPersonRecord($matchingPerson);
    app(PersonSearchService::class)->syncPersonRecord($otherPerson);

    $matchingInstitution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'status' => 'verified',
    ]);
    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    $matchingReference = Reference::factory()->create([
        'title' => 'Rujukan Tajwid',
        'author' => 'Imam Contoh',
        'description' => 'Syarahan tajwid dan adab',
        'status' => 'verified',
    ]);
    Reference::factory()->create([
        'title' => 'Rujukan Lain',
        'status' => 'verified',
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/people?search='.urlencode('syeikhul maqari'))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', (string) $matchingPerson->getKey());

    $this->getJson('/api/v1/admin/institutions?search='.urlencode('Masjid Biru'))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', (string) $matchingInstitution->getKey());

    $this->getJson('/api/v1/admin/references?search='.urlencode('tajwid adab'))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', (string) $matchingReference->getKey());
});

it('filters admin event records by explicit query parameters', function () {
    $admin = adminApiUser('super_admin');

    $draftOnlineEvent = Event::factory()->create([
        'title' => 'Admin API Draft Online Event',
        'status' => 'draft',
        'delivery_mode' => EventFormat::Online,
        'visibility' => EventVisibility::Public,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
    ]);

    $approvedPhysicalEvent = Event::factory()->create([
        'title' => 'Admin API Approved Physical Event',
        'status' => 'approved',
        'delivery_mode' => EventFormat::Physical,
        'visibility' => EventVisibility::Private,
        'event_category_ids' => [eventCategoryId('forum')],
    ]);

    $cancelledHybridEvent = Event::factory()->create([
        'title' => 'Admin API Cancelled Hybrid Event',
        'status' => 'cancelled',
        'delivery_mode' => EventFormat::Hybrid,
        'visibility' => EventVisibility::Unlisted,
        'event_category_ids' => [eventCategoryId('kenduri')],
    ]);

    Sanctum::actingAs($admin);

    $metaResponse = $this->getJson('/api/v1/admin/events/meta')
        ->assertOk();

    expect(collect($metaResponse->json('data.resource.filters'))->pluck('key')->all())
        ->toContain('status', 'visibility', 'event_format', 'event_category_ids', 'timing_mode', 'prayer_reference');

    $draftResponse = $this->getJson('/api/v1/admin/events?filter[status]=draft')
        ->assertOk();

    expect($draftResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($draftResponse->json('data'))->pluck('route_key')->all())->toContain($draftOnlineEvent->getRouteKey())
        ->and(collect($draftResponse->json('data'))->pluck('route_key')->all())->not->toContain($approvedPhysicalEvent->getRouteKey())
        ->and(collect($draftResponse->json('data'))->pluck('route_key')->all())->not->toContain($cancelledHybridEvent->getRouteKey());

    $onlineResponse = $this->getJson('/api/v1/admin/events?filter[event_format]=online')
        ->assertOk();

    expect($onlineResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($onlineResponse->json('data'))->pluck('route_key')->all())->toContain($draftOnlineEvent->getRouteKey())
        ->and(collect($onlineResponse->json('data'))->pluck('route_key')->all())->not->toContain($approvedPhysicalEvent->getRouteKey())
        ->and(collect($onlineResponse->json('data'))->pluck('route_key')->all())->not->toContain($cancelledHybridEvent->getRouteKey());

    $privateResponse = $this->getJson('/api/v1/admin/events?filter[visibility]=private')
        ->assertOk();

    expect($privateResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($privateResponse->json('data'))->pluck('route_key')->all())->toContain($approvedPhysicalEvent->getRouteKey())
        ->and(collect($privateResponse->json('data'))->pluck('route_key')->all())->not->toContain($draftOnlineEvent->getRouteKey())
        ->and(collect($privateResponse->json('data'))->pluck('route_key')->all())->not->toContain($cancelledHybridEvent->getRouteKey());

    $approvedResponse = $this->getJson('/api/v1/admin/events?filter[status]=approved')
        ->assertOk();

    expect($approvedResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($approvedResponse->json('data'))->pluck('route_key')->all())->toContain($approvedPhysicalEvent->getRouteKey())
        ->and(collect($approvedResponse->json('data'))->pluck('route_key')->all())->not->toContain($draftOnlineEvent->getRouteKey())
        ->and(collect($approvedResponse->json('data'))->pluck('route_key')->all())->not->toContain($cancelledHybridEvent->getRouteKey());

    $eventTypeResponse = $this->getJson('/api/v1/admin/events?filter[event_category_ids]='.eventCategoryId('kuliah_ceramah'))
        ->assertOk();

    expect($eventTypeResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($eventTypeResponse->json('data'))->pluck('route_key')->all())->toContain($draftOnlineEvent->getRouteKey())
        ->and(collect($eventTypeResponse->json('data'))->pluck('route_key')->all())->not->toContain($approvedPhysicalEvent->getRouteKey())
        ->and(collect($eventTypeResponse->json('data'))->pluck('route_key')->all())->not->toContain($cancelledHybridEvent->getRouteKey());
});

it('allows admin api event create payload to control initial workflow status', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/events/schema?operation=create')
        ->assertOk()
        ->json('data.schema');

    $statusField = collect($schema['fields'] ?? [])->firstWhere('name', 'status');

    expect($statusField)->toBeArray()
        ->and(data_get($statusField, 'default'))->toBe('draft')
        ->and(data_get($statusField, 'allowed_values'))->toBe(['draft', 'pending', 'approved']);

    $basePayload = [
        'description' => '<p>Admin API event status payload test</p>',
        'event_date' => '2026-05-09',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'is_muslim_only' => false,
        'event_category_ids' => [eventCategoryId('bacaan_yasin')],
        'primary_organizer_id' => (string) $institution->getKey(),
        'institution_id' => (string) $institution->getKey(),
        'registration_required' => false,
        'registration_mode' => RegistrationScope::Event->value,
        'is_featured' => false,
        'status' => 'draft',
    ];

    $draftResponse = $this->postJson('/api/v1/admin/events', array_replace($basePayload, [
        'title' => 'Admin API Draft Status Payload Event',
        'status' => 'draft',
    ]))->assertCreated();

    $draftRouteKey = (string) $draftResponse->json('data.record.route_key');
    $draftEvent = Event::query()->findOrFail($draftRouteKey);

    expect((string) $draftEvent->status)->toBe('draft')
        ->and($draftEvent->published_at)->toBeNull();

    $moderationSchemaResponse = $this->getJson('/api/v1/admin/events/'.$draftRouteKey.'/moderation-schema')
        ->assertOk();

    $actionField = collect($moderationSchemaResponse->json('data.schema.fields', []))
        ->firstWhere('name', 'action');

    expect($actionField['allowed_values'] ?? [])
        ->toContain('submit_for_moderation');

    $approvedResponse = $this->postJson('/api/v1/admin/events', array_replace($basePayload, [
        'title' => 'Admin API Approved Status Payload Event',
        'status' => 'approved',
    ]))->assertCreated();

    $approvedRouteKey = (string) $approvedResponse->json('data.record.route_key');
    $approvedEvent = Event::query()->findOrFail($approvedRouteKey);

    expect((string) $approvedEvent->status)->toBe('approved')
        ->and($approvedEvent->published_at)->not->toBeNull();
});

it('filters admin event records by top-level date parameters and combines date filters with search', function () {
    $admin = adminApiUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $matchingDateAndStatusEvent = Event::factory()->create([
        'title' => 'Admin API Date Plus Status Match',
        'starts_at' => Carbon::parse('2026-05-10 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin API Date Plus Status Wrong Status',
        'starts_at' => Carbon::parse('2026-05-10 05:00:00', 'UTC'),
        'status' => 'draft',
    ]);

    $withinRange = Event::factory()->create([
        'title' => 'Admin API Date Range Within',
        'starts_at' => Carbon::parse('2026-05-12 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin API Date Range Outside',
        'starts_at' => Carbon::parse('2026-05-15 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    $searchDateMatch = Event::factory()->create([
        'title' => 'Admin API Search Dhuha Match',
        'starts_at' => Carbon::parse('2026-05-10 03:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin API Search Dhuha Wrong Date',
        'starts_at' => Carbon::parse('2026-05-14 03:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Sanctum::actingAs($admin);

    $dateAndStatusResponse = $this->getJson('/api/v1/admin/events?starts_on_local_date=2026-05-10&filter[status]=approved')
        ->assertOk();

    expect($dateAndStatusResponse->json('meta.pagination.total'))->toBe(2)
        ->and(collect($dateAndStatusResponse->json('data'))->pluck('route_key')->all())->toContain($matchingDateAndStatusEvent->getRouteKey(), $searchDateMatch->getRouteKey());

    $rangeResponse = $this->getJson('/api/v1/admin/events?starts_after=2026-05-11&starts_before=2026-05-13')
        ->assertOk();

    expect($rangeResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($rangeResponse->json('data'))->pluck('route_key')->all())->toContain($withinRange->getRouteKey());

    $searchAndDateResponse = $this->getJson('/api/v1/admin/events?search=Dhuha&starts_on_local_date=2026-05-10')
        ->assertOk();

    expect($searchAndDateResponse->json('meta.pagination.total'))->toBe(1)
        ->and(collect($searchAndDateResponse->json('data'))->pluck('route_key')->all())->toContain($searchDateMatch->getRouteKey());
});

it('surfaces public event change projections on admin event detail payloads', function () {
    $admin = adminApiUser('super_admin');
    $actor = User::factory()->create();
    $original = Event::factory()->create([
        'title' => 'Admin API Change Surface Original',
        'slug' => 'admin-api-change-surface-original',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);
    $firstReplacement = Event::factory()->create([
        'title' => 'Admin API Change Surface First Replacement',
        'slug' => 'admin-api-change-surface-first-replacement',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);
    $finalReplacement = Event::factory()->create([
        'title' => 'Admin API Change Surface Final Replacement',
        'slug' => 'admin-api-change-surface-final-replacement',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    EventChangeAnnouncement::unguarded(function () use ($actor, $original, $firstReplacement, $finalReplacement): void {
        EventChangeAnnouncement::query()->create([
            'event_id' => $original->id,
            'replacement_event_id' => $firstReplacement->id,
            'created_by_type' => User::class,
            'created_by_id' => $actor->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Sila rujuk majlis pengganti pertama.',
            'metadata' => [
                'status' => 'published',
                'changed_fields' => [],
            ],

            'published_at' => Carbon::parse('2026-05-05 12:00:00', 'UTC'),
            'created_at' => Carbon::parse('2026-05-05 12:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-05-05 12:00:00', 'UTC'),
        ]);

        EventChangeAnnouncement::query()->create([
            'event_id' => $firstReplacement->id,
            'replacement_event_id' => $finalReplacement->id,
            'created_by_type' => User::class,
            'created_by_id' => $actor->id,
            'update_type' => EventChangeType::ReplacementLinked,
            'severity' => EventChangeSeverity::High,
            'message' => 'Majlis pengganti pertama diganti pula.',
            'metadata' => [
                'status' => 'published',
                'changed_fields' => [],
            ],

            'published_at' => Carbon::parse('2026-05-05 12:05:00', 'UTC'),
            'created_at' => Carbon::parse('2026-05-05 12:05:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-05-05 12:05:00', 'UTC'),
        ]);

        EventChangeAnnouncement::query()->create([
            'event_id' => $original->id,
            'created_by_type' => User::class,
            'created_by_id' => $actor->id,
            'update_type' => EventChangeType::Other,
            'severity' => EventChangeSeverity::Info,
            'message' => 'Nota terkini untuk pautan lama.',
            'metadata' => [
                'status' => 'published',
                'changed_fields' => ['title'],
            ],

            'published_at' => Carbon::parse('2026-05-05 12:10:00', 'UTC'),
            'created_at' => Carbon::parse('2026-05-05 12:10:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-05-05 12:10:00', 'UTC'),
        ]);
    });

    $finalReplacement->update([
        'visibility' => EventVisibility::Private,
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/events/'.$original->getRouteKey())
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'events')
        ->assertJsonPath('data.record.attributes.active_change_notice.type', EventChangeType::Other->value)
        ->assertJsonPath('data.record.attributes.active_change_notice.public_message', 'Nota terkini untuk pautan lama.')
        ->assertJsonPath('data.record.attributes.replacement_event.id', $firstReplacement->id)
        ->assertJsonPath('data.record.attributes.replacement_event.route_key', $firstReplacement->getRouteKey())
        ->assertJsonPath('data.record.attributes.change_announcements.1.type', EventChangeType::ReplacementLinked->value)
        ->assertJsonPath('data.record.attributes.change_announcements.1.replacement_event.id', $firstReplacement->id)
        ->assertJsonMissingPath('data.record.attributes.latest_published_change_announcement')
        ->assertJsonMissingPath('data.record.attributes.latest_published_replacement_announcement')
        ->assertJsonMissingPath('data.record.attributes.published_change_announcements');
});

it('previews admin person creation without persisting the record', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/people?validate_only=1', [
        'name' => 'Previewed Admin API Person',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertOk();

    $response
        ->assertJsonPath('data.resource.key', 'people')
        ->assertJsonPath('data.preview.validate_only', true)
        ->assertJsonPath('data.preview.operation', 'create')
        ->assertJsonPath('data.preview.normalized_payload.address.country_id', ensureAdminApiMalaysiaCountryExists())
        ->assertJsonPath('data.preview.current_record', null);

    expect(Person::query()->where('name', 'Previewed Admin API Person')->exists())->toBeFalse();
});

it('previews admin person updates without persisting the record', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    $person = Person::factory()->create([
        'name' => 'Previewable Admin API Person',
        'job_title' => null,
    ]);
    $originalName = (string) $person->name;
    $personRouteKey = (string) $person->getRouteKey();

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/v1/admin/people/'.$personRouteKey.'?validate_only=1', [
        'name' => 'Previewed Admin API Person Updated',
        'gender' => 'male',
        'status' => 'verified',
        'allow_public_event_submission' => true,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
        'clear_cover' => true,
    ])->assertOk();

    $response
        ->assertJsonPath('data.resource.key', 'people')
        ->assertJsonPath('data.preview.validate_only', true)
        ->assertJsonPath('data.preview.operation', 'update')
        ->assertJsonPath('data.preview.current_record.route_key', $personRouteKey)
        ->assertJsonPath('data.preview.destructive_media_fields.0', 'clear_cover')
        ->assertJsonPath('data.preview.warnings.0.field', 'clear_cover');

    expect(Person::query()->findOrFail($person->getKey())->name)->toBe($originalName);
});

it('returns autofill hints and conditional requirements in admin api dry runs', function () {
    ensureAdminApiMalaysiaCountryExists();
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/events?validate_only=1&apply_defaults=1', [
        'title' => 'Admin API AI Feedback Preview',
        'event_date' => '2026-06-10',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.feedback.validate_only', true)
        ->assertJsonPath('error.details.feedback.apply_defaults', true)
        ->assertJsonPath('error.details.feedback.normalized_payload.timezone', 'Asia/Kuala_Lumpur')
        ->assertJsonPath('error.details.feedback.normalized_payload.event_format', EventFormat::Physical->value)
        ->assertJsonPath('error.details.feedback.normalized_payload.prayer_time', EventPrayerTime::LainWaktu->value)
        ->assertJsonPath('error.details.feedback.issues.0.field', 'custom_time')
        ->assertJsonPath('error.details.feedback.issues.0.required_because.prayer_time', EventPrayerTime::LainWaktu->value);
});

it('returns remediation details for validate-only admin api create validation failures', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/people?validate_only=1', [
        'name' => 'Remediation Preview API Person',
    ])->assertUnprocessable();

    $fixPlan = collect($response->json('error.details.fix_plan'))->keyBy('field');
    $remainingBlockers = collect($response->json('error.details.remaining_blockers'))->keyBy('field');

    $response
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.normalized_payload_preview.name', 'Remediation Preview API Person')
        ->assertJsonPath('error.details.normalized_payload_preview.gender', 'male')
        ->assertJsonMissingPath('error.details.normalized_payload_preview.address')
        ->assertJsonPath('error.details.can_retry', false);

    expect($fixPlan->get('gender'))
        ->toMatchArray([
            'action' => 'set_field',
            'field' => 'gender',
            'value' => 'male',
            'auto_apply_safe' => true,
        ])
        ->and($fixPlan->get('status'))->toMatchArray([
            'action' => 'set_field',
            'field' => 'status',
            'value' => 'verified',
            'auto_apply_safe' => true,
        ])
        ->and($fixPlan->has('address'))->toBeFalse()
        ->and($remainingBlockers->keys()->all())->toContain('address', 'address.country_id');
});

it('returns structured enum suggestions for admin api validation errors', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/events', [
        'title' => 'Admin API Invalid Enum Preview',
        'event_date' => '2026-06-10',
        'custom_time' => '8:30 PM',
        'event_format' => 'physicl',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.feedback.validate_only', false)
        ->assertJsonPath('error.details.feedback.apply_defaults', false);

    $issue = collect($response->json('error.details.feedback.issues'))
        ->firstWhere('field', 'event_format');

    expect($issue)
        ->toBeArray()
        ->and($issue['closest_valid_value'] ?? null)->toBe(EventFormat::Physical->value)
        ->and($issue['suggested'] ?? null)->toBe(EventFormat::Physical->value)
        ->and(data_get($issue, 'allowed_values.0'))->toBe(EventFormat::Physical->value);
});

it('returns retryable remediation details for validate-only admin api update validation failures', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    $person = Person::factory()->create([
        'name' => 'Retryable Admin API Person',
        'gender' => 'male',
        'status' => 'verified',
    ]);
    $personRouteKey = (string) $person->getRouteKey();

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/v1/admin/people/'.$personRouteKey.'?validate_only=1', [
        'name' => 'Retryable Admin API Person Updated',
    ])->assertUnprocessable();

    $fixPlan = collect($response->json('error.details.fix_plan'))->keyBy('field');

    $response
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.details.normalized_payload_preview.name', 'Retryable Admin API Person Updated')
        ->assertJsonPath('error.details.normalized_payload_preview.gender', $person->gender)
        ->assertJsonPath('error.details.normalized_payload_preview.status', $person->status)
        ->assertJsonCount(0, 'error.details.remaining_blockers')
        ->assertJsonPath('error.details.can_retry', true);

    expect($fixPlan->get('gender'))->toMatchArray([
        'action' => 'set_field',
        'field' => 'gender',
        'value' => $person->gender,
        'auto_apply_safe' => true,
    ])->and($fixPlan->get('status'))->toMatchArray([
        'action' => 'set_field',
        'field' => 'status',
        'value' => $person->status,
        'auto_apply_safe' => true,
    ]);
});

it('lists related records for admin resource relations', function () {
    $admin = adminApiUser('super_admin');
    $relatedTitle = 'Nested Relation Event '.Str::ulid();
    $person = Person::factory()->create([
        'name' => 'Nested Relation Person',
    ]);
    $event = Event::factory()->create([
        'title' => $relatedTitle,
    ]);

    $event->persons()->attach($person);
    $personRouteKey = (string) $person->getRouteKey();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/people/meta')
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->where('data.resource.api_routes.related_collection', '/api/v1/admin/people/record/relations/relation')
            ->where('data.resource.relations', fn ($relations): bool => collect($relations)->contains('events'))
            ->etc());

    $response = $this->getJson('/api/v1/admin/people/'.$personRouteKey.'/relations/events?search='.urlencode($relatedTitle))
        ->assertOk();

    $response
        ->assertJsonPath('data.0.route_key', $event->getRouteKey())
        ->assertJsonPath('data.0.title', $relatedTitle)
        ->assertJsonPath('meta.resource.key', 'people')
        ->assertJsonPath('meta.parent_record.route_key', $personRouteKey)
        ->assertJsonPath('meta.relation.name', 'events')
        ->assertJsonPath('meta.relation.related_resource.key', 'events');
});

it('exposes event moderation schema and can request changes through the admin workflow endpoints', function () {
    $admin = adminApiUser('super_admin');
    $event = Event::factory()->create([
        'status' => 'pending',
    ]);

    Sanctum::actingAs($admin);

    $schemaResponse = $this->getJson('/api/v1/admin/events/'.$event->getRouteKey().'/moderation-schema')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'events')
        ->assertJsonPath('data.record.route_key', $event->getRouteKey())
        ->assertJsonPath('data.schema.action', 'moderate_event')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/events/'.$event->getRouteKey().'/moderate');

    $actionField = collect($schemaResponse->json('data.schema.fields'))->firstWhere('name', 'action');

    expect($actionField['allowed_values'] ?? [])
        ->toContain('approve', 'request_changes', 'reject', 'cancel')
        ->not->toContain('reconsider', 'remoderate', 'revert_to_draft');

    $this->postJson('/api/v1/admin/events/'.$event->getRouteKey().'/moderate', [
        'action' => 'request_changes',
        'reason_code' => 'incomplete_info',
        'note' => 'Please add venue details before approval.',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.status', 'needs_changes');

    $event->refresh();

    expect((string) $event->status)->toBe('needs_changes');

    $review = OwnerContext::withOwner(null, fn () => ModerationReview::query()
        ->where('actionable_type', Event::class)
        ->where('actionable_id', $event->getKey())
        ->latest()
        ->first());

    expect($review?->type)->toBe(ModerationActionType::ChangesRequested)
        ->and($review?->reason)->toBe('incomplete_info');
});

it('exposes contribution request review schema and can approve requests through the admin workflow endpoints', function () {
    $admin = adminApiUser('moderator');
    $institution = Institution::factory()->create([
        'description' => 'Before review',
        'status' => 'verified',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'After review',
        ],
        'original_data' => [
            'description' => 'Before review',
        ],
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/contribution-requests/'.$request->getRouteKey().'/review-schema')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'contribution-requests')
        ->assertJsonPath('data.record.route_key', $request->getRouteKey())
        ->assertJsonPath('data.schema.action', 'review_contribution_request')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/contribution-requests/'.$request->getRouteKey().'/review')
        ->assertJsonPath('data.schema.conditional_rules.0.field', 'reason_code');

    $this->postJson('/api/v1/admin/contribution-requests/'.$request->getRouteKey().'/review', [
        'action' => 'approve',
        'reviewer_note' => 'Looks accurate.',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.status', 'approved')
        ->assertJsonPath('data.record.attributes.reviewer_note', 'Looks accurate.');

    expect($request->fresh()?->status)->toBe(ContributionRequestStatus::Approved)
        ->and($request->fresh()?->reviewer_id)->toBe($admin->getKey())
        ->and($request->fresh()?->reviewer_note)->toBe('Looks accurate.')
        ->and($institution->fresh()?->description)->toBe('After review');
});

it('exposes report triage schema and can resolve reports through the admin workflow endpoints', function () {
    $admin = adminApiUser('moderator');
    $reporter = User::factory()->create();
    $report = Report::factory()->create([
        'reporter_id' => $reporter->getKey(),
        'status' => 'open',
        'handled_by' => null,
        'resolution_note' => null,
    ]);

    Sanctum::actingAs($admin);

    $schemaResponse = $this->getJson('/api/v1/admin/reports/'.$report->getRouteKey().'/triage-schema')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'reports')
        ->assertJsonPath('data.record.route_key', $report->getRouteKey())
        ->assertJsonPath('data.schema.action', 'triage_report')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/reports/'.$report->getRouteKey().'/triage');

    $actionField = collect($schemaResponse->json('data.schema.fields'))->firstWhere('name', 'action');

    expect($actionField['allowed_values'] ?? [])
        ->toContain('triage', 'resolve', 'dismiss')
        ->not->toContain('reopen');

    $this->postJson('/api/v1/admin/reports/'.$report->getRouteKey().'/triage', [
        'action' => 'resolve',
        'resolution_note' => 'Handled through the admin API.',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.status', 'resolved')
        ->assertJsonPath('data.record.attributes.resolution_note', 'Handled through the admin API.');

    $report->refresh();

    expect($report->status)->toBe('resolved')
        ->and($report->handled_by)->toBe($admin->getKey())
        ->and($report->resolution_note)->toBe('Handled through the admin API.');
});

it('exposes report write schema and can create and update reports through the api', function () {
    $admin = adminApiUser('moderator');
    $reporter = User::factory()->create();
    $event = Event::factory()->create();
    $reference = Reference::factory()->verified()->create();

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/reports/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'reports')
        ->assertJsonPath('data.schema.resource_key', 'reports')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/reports')
        ->json('data.schema');

    expect(collect($schema['fields'] ?? [])->pluck('name')->all())
        ->toContain('entity_type', 'entity_id', 'category', 'status', 'reporter_id', 'handled_by', 'resolution_note', 'evidence', 'clear_evidence');

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/reports', [
        'entity_type' => 'event',
        'entity_id' => (string) $event->getKey(),
        'category' => 'wrong_info',
        'description' => 'Created through admin API.',
        'status' => 'open',
        'reporter_id' => (string) $reporter->getKey(),
        'evidence' => [
            fakeGeneratedImageUpload('admin-api-report-evidence.png', 640, 640),
        ],
    ])->assertCreated();

    $reportRouteKey = (string) $createResponse->json('data.record.route_key');
    $report = Report::query()->with(['entity', 'reporter', 'handler', 'media'])->findOrFail($reportRouteKey);

    expect($report->entity_type)->toBe('event')
        ->and($report->entity_id)->toBe($event->getKey())
        ->and($report->category)->toBe('wrong_info')
        ->and($report->status)->toBe('open')
        ->and($report->reporter_id)->toBe($reporter->getKey())
        ->and($report->handled_by)->toBeNull()
        ->and($report->getMedia('evidence'))->toHaveCount(1);

    $this->getJson('/api/v1/admin/reports/schema?operation=update&recordKey='.$reportRouteKey)
        ->assertOk()
        ->assertJsonPath('data.schema.method', 'PUT')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/reports/'.$reportRouteKey)
        ->assertJsonPath('data.schema.defaults.entity_type', 'event')
        ->assertJsonPath('data.schema.defaults.entity_id', (string) $event->getKey())
        ->assertJsonPath('data.schema.defaults.clear_evidence', false);

    $this->putJson('/api/v1/admin/reports/'.$reportRouteKey, [
        'entity_type' => 'reference',
        'entity_id' => (string) $reference->getKey(),
        'category' => 'fake_reference',
        'description' => 'Updated through admin API.',
        'status' => 'resolved',
        'reporter_id' => (string) $reporter->getKey(),
        'handled_by' => (string) $admin->getKey(),
        'resolution_note' => 'Resolved through admin API.',
        'clear_evidence' => true,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.entity_type', 'reference')
        ->assertJsonPath('data.record.attributes.category', 'fake_reference')
        ->assertJsonPath('data.record.attributes.status', 'resolved')
        ->assertJsonPath('data.record.attributes.resolution_note', 'Resolved through admin API.');

    $report->refresh();

    expect($report->entity_type)->toBe('reference')
        ->and($report->entity_id)->toBe($reference->getKey())
        ->and($report->category)->toBe('fake_reference')
        ->and($report->status)->toBe('resolved')
        ->and($report->handled_by)->toBe($admin->getKey())
        ->and($report->resolution_note)->toBe('Resolved through admin API.')
        ->and($report->getMedia('evidence'))->toHaveCount(0);
});

it('surfaces report update semantics through the admin api schema', function () {
    $admin = adminApiUser('moderator');
    $report = Report::factory()->create([
        'status' => 'open',
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/reports/schema?operation=update&recordKey='.$report->getRouteKey())
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('entity_type'), 'paired_with'))->toBe('entity_id')
        ->and(data_get($fields->get('category'), 'allowed_values_resolved_from'))->toBe('entity_type')
        ->and(data_get($fields->get('description'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('reporter_id'), 'relation'))->toBe('users')
        ->and(data_get($fields->get('handled_by'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('resolution_note'), 'normalization.empty_string_at_mutation_layer'))->toBe('null')
        ->and(data_get($fields->get('evidence'), 'collection_semantics.explicit_null'))->toBe('preserve_existing_collection')
        ->and(data_get($fields->get('evidence'), 'collection_semantics.empty_array'))->toBe('clear_collection')
        ->and(data_get($fields->get('evidence'), 'raw_http_clear_flag'))->toBe('clear_evidence');
});

it('clears report optional scalars and evidence through the admin api', function () {
    $admin = adminApiUser('moderator');
    $reporter = User::factory()->create();
    $handler = User::factory()->create();
    $event = Event::factory()->create();

    Sanctum::actingAs($admin);

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/reports', [
        'entity_type' => 'event',
        'entity_id' => (string) $event->getKey(),
        'category' => 'wrong_info',
        'description' => 'Legacy report description.',
        'status' => 'triaged',
        'reporter_id' => (string) $reporter->getKey(),
        'handled_by' => (string) $handler->getKey(),
        'resolution_note' => 'Legacy resolution note.',
        'evidence' => [
            fakeGeneratedImageUpload('admin-api-report-evidence-reset.png', 640, 640),
        ],
    ])->assertCreated();

    $reportRouteKey = (string) $createResponse->json('data.record.route_key');
    $report = Report::query()->findOrFail($reportRouteKey);

    $this->putJson('/api/v1/admin/reports/'.$reportRouteKey, [
        'entity_type' => 'event',
        'entity_id' => (string) $event->getKey(),
        'category' => 'wrong_info',
        'description' => '',
        'status' => 'open',
        'reporter_id' => null,
        'handled_by' => null,
        'resolution_note' => '',
        'evidence' => [],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.description', null)
        ->assertJsonPath('data.record.attributes.reporter_id', null)
        ->assertJsonPath('data.record.attributes.handled_by', null)
        ->assertJsonPath('data.record.attributes.resolution_note', null);

    $report->refresh();

    expect($report->description)->toBeNull()
        ->and($report->reporter_id)->toBeNull()
        ->and($report->handled_by)->toBeNull()
        ->and($report->resolution_note)->toBeNull()
        ->and($report->getMedia('evidence'))->toHaveCount(0);
});

it('exposes inspiration write schema and can create and update inspirations through the api', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/inspirations/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'inspirations')
        ->assertJsonPath('data.schema.resource_key', 'inspirations')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/inspirations')
        ->json('data.schema');

    expect(collect($schema['fields'] ?? [])->pluck('name')->all())
        ->toContain('category', 'locale', 'title', 'content', 'source', 'main', 'clear_main');

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/inspirations', [
        'category' => 'quran_quote',
        'locale' => 'ms',
        'title' => 'Admin API Inspiration',
        'content' => 'Admin API inspiration content.',
        'source' => 'Admin API Source',
        'status' => 'active',
        'main' => fakeGeneratedImageUpload('admin-api-inspiration-main.png', 1280, 720),
    ])->assertCreated();

    $inspirationRouteKey = (string) $createResponse->json('data.record.route_key');
    $inspiration = Inspiration::query()->findOrFail($inspirationRouteKey);

    expect($inspiration->getRawOriginal('category'))->toBe('quran_quote')
        ->and($inspiration->locale)->toBe('ms')
        ->and($inspiration->title)->toBe('Admin API Inspiration')
        ->and((string) $inspiration->status)->toBe('active')
        ->and($inspiration->getMedia('main'))->toHaveCount(1);

    $this->putJson('/api/v1/admin/inspirations/'.$inspirationRouteKey, [
        'category' => 'hadith_quote',
        'locale' => 'en',
        'title' => 'Admin API Inspiration Updated',
        'content' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Updated inspiration content.',
                ]],
            ]],
        ],
        'source' => 'Updated API Source',
        'status' => 'inactive',
        'clear_main' => true,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.category', 'hadith_quote')
        ->assertJsonPath('data.record.attributes.locale', 'en')
        ->assertJsonPath('data.record.attributes.title', 'Admin API Inspiration Updated')
        ->assertJsonPath('data.record.attributes.status', 'inactive');

    $inspiration->refresh();

    expect($inspiration->getRawOriginal('category'))->toBe('hadith_quote')
        ->and($inspiration->locale)->toBe('en')
        ->and($inspiration->title)->toBe('Admin API Inspiration Updated')
        ->and($inspiration->source)->toBe('Updated API Source')
        ->and((string) $inspiration->status)->toBe('inactive')
        ->and($inspiration->getMedia('main'))->toHaveCount(0);
});

it('surfaces inspiration update semantics through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    $inspiration = Inspiration::factory()->create([
        'source' => 'Schema Source',
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/inspirations/schema?operation=update&recordKey='.$inspiration->getRouteKey())
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('content'), 'input_normalization.kind'))->toBe('rich_text_document')
        ->and(data_get($fields->get('content'), 'input_normalization.accepts_plain_string'))->toBeTrue()
        ->and(data_get($fields->get('source'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('main'), 'mutation_semantics'))->toBe('replace_single_media_collection')
        ->and(data_get($fields->get('main'), 'clear_semantics.explicit_null'))->toBe('preserve_existing_collection')
        ->and(data_get($fields->get('main'), 'raw_http_clear_flag'))->toBe('clear_main');
});

it('clears inspiration source while preserving existing main media through the admin api', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/inspirations', [
        'category' => 'quran_quote',
        'locale' => 'ms',
        'title' => 'Admin API Inspiration Preserve Main',
        'content' => 'Original inspiration content.',
        'source' => 'Original inspiration source.',
        'status' => 'active',
        'main' => fakeGeneratedImageUpload('admin-api-inspiration-preserve-main.png', 1280, 720),
    ])->assertCreated();

    $inspirationRouteKey = (string) $createResponse->json('data.record.route_key');
    $inspiration = Inspiration::query()->findOrFail($inspirationRouteKey);

    $this->putJson('/api/v1/admin/inspirations/'.$inspirationRouteKey, [
        'category' => 'quran_quote',
        'locale' => 'ms',
        'title' => 'Admin API Inspiration Preserve Main',
        'content' => 'Updated inspiration content.',
        'source' => '',
        'status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.source', null);

    $inspiration->refresh();

    expect($inspiration->source)->toBeNull()
        ->and($inspiration->getMedia('main'))->toHaveCount(1);
});

it('exposes series write schema and can create and update series through the api', function () {
    $admin = adminApiUser('super_admin');
    $suffix = Str::lower((string) Str::ulid());

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/series/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'series')
        ->assertJsonPath('data.schema.resource_key', 'series')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/series')
        ->json('data.schema');

    expect(collect($schema['fields'] ?? [])->pluck('name')->all())
        ->toContain('title', 'slug', 'description', 'visibility', 'languages', 'cover', 'gallery');

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/series', [
        'title' => 'Admin API Series '.$suffix,
        'slug' => 'admin-api-series-'.$suffix,
        'description' => 'Series created through the admin API.',
        'visibility' => 'public',
        'status' => 'active',
        'cover' => fakeGeneratedImageUpload('series-cover.png', 1280, 720),
        'gallery' => [
            fakeGeneratedImageUpload('series-gallery.png', 1280, 720),
        ],
    ])->assertCreated();

    $seriesRouteKey = (string) $createResponse->json('data.record.route_key');
    $series = Series::query()->findOrFail($seriesRouteKey);

    expect($series->title)->toBe('Admin API Series '.$suffix)
        ->and($series->slug)->toBe('admin-api-series-'.$suffix)
        ->and($series->getMedia('cover'))->toHaveCount(1)
        ->and($series->getMedia('gallery'))->toHaveCount(1);

    $this->putJson('/api/v1/admin/series/'.$seriesRouteKey, [
        'title' => 'Admin API Series Updated '.$suffix,
        'slug' => 'admin-api-series-updated-'.$suffix,
        'description' => 'Series updated through the admin API.',
        'visibility' => 'private',
        'status' => 'inactive',
        'languages' => [],
        'clear_cover' => true,
        'clear_gallery' => true,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.title', 'Admin API Series Updated '.$suffix)
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-series-updated-'.$suffix)
        ->assertJsonPath('data.record.attributes.visibility', 'private')
        ->assertJsonPath('data.record.attributes.status', 'inactive');

    $series->refresh();

    expect($series->title)->toBe('Admin API Series Updated '.$suffix)
        ->and($series->slug)->toBe('admin-api-series-updated-'.$suffix)
        ->and($series->visibility)->toBe('private')
        ->and((string) $series->status)->toBe('inactive')
        ->and($series->getMedia('cover'))->toHaveCount(0)
        ->and($series->getMedia('gallery'))->toHaveCount(0);
});

it('surfaces series update semantics through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    $series = Series::factory()->create([
        'description' => 'Series schema surface',
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/series/schema?operation=update&recordKey='.$series->getKey())
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('title'), 'required'))->toBeTrue()
        ->and(data_get($fields->get('slug'), 'uniqueness_scope'))->toBe('series.slug')
        ->and(data_get($fields->get('description'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('languages'), 'relation'))->toBe('languages')
        ->and(data_get($fields->get('languages'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('languages'), 'collection_semantics.submitted_array'))->toBe('replace_relation_sync');
});

it('clears series description and languages through the admin api', function () {
    $languageMalay = Language::where('code', 'ms')->first() ?? Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'name_native' => 'Bahasa Melayu',
        'dir' => 'ltr',
    ]);

    $admin = adminApiUser('super_admin');
    $series = Series::factory()->create([
        'description' => 'Series with languages',
        'visibility' => 'public',
    ]);
    $series->languages()->sync([$languageMalay->id]);

    Sanctum::actingAs($admin);

    $this->putJson('/api/v1/admin/series/'.$series->getRouteKey(), [
        'title' => $series->title,
        'slug' => $series->slug,
        'visibility' => 'public',
        'description' => '',
        'languages' => null,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.description', null);

    $series->refresh()->load('languages');

    expect($series->description)->toBeNull()
        ->and($series->languages)->toHaveCount(0);
});

it('exposes space write schema and can create and update spaces through the api', function () {
    $admin = adminApiUser('super_admin');
    $suffix = Str::lower((string) Str::ulid());
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/spaces/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'spaces')
        ->assertJsonPath('data.schema.resource_key', 'spaces')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.content_type', 'application/json')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/spaces')
        ->json('data.schema');

    expect(collect($schema['fields'] ?? [])->pluck('name')->all())
        ->toContain('name', 'slug', 'capacity', 'status', 'institutions');

    $createResponse = $this->postJson('/api/v1/admin/spaces', [
        'name' => 'Admin API Space '.$suffix,
        'slug' => 'admin-api-space-'.$suffix,
        'capacity' => 80,
        'status' => 'active',
        'institutions' => [(string) $firstInstitution->getKey()],
    ])->assertCreated();

    $spaceRouteKey = (string) $createResponse->json('data.record.route_key');
    $space = Space::query()->findOrFail($spaceRouteKey);

    expect($space->name)->toBe('Admin API Space '.$suffix)
        ->and($space->slug)->toBe('admin-api-space-'.$suffix)
        ->and($space->capacity)->toBe(80)
        ->and($space->institutions()->pluck('institutions.id')->all())->toContain($firstInstitution->getKey());

    $this->putJson('/api/v1/admin/spaces/'.$spaceRouteKey, [
        'name' => 'Admin API Space Updated '.$suffix,
        'slug' => 'admin-api-space-updated-'.$suffix,
        'capacity' => 120,
        'status' => 'inactive',
        'institutions' => [(string) $secondInstitution->getKey()],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Space Updated '.$suffix)
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-space-updated-'.$suffix)
        ->assertJsonPath('data.record.attributes.capacity', 120)
        ->assertJsonPath('data.record.attributes.status', 'inactive');

    $space->refresh();

    expect($space->name)->toBe('Admin API Space Updated '.$suffix)
        ->and($space->slug)->toBe('admin-api-space-updated-'.$suffix)
        ->and($space->capacity)->toBe(120)
        ->and((string) $space->status)->toBe('inactive')
        ->and($space->institutions()->pluck('institutions.id')->all())->toContain($secondInstitution->getKey())
        ->and($space->institutions()->pluck('institutions.id')->all())->not->toContain($firstInstitution->getKey());
});

it('surfaces space update semantics through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    $space = Space::factory()->create([
        'slug' => 'admin-api-space-schema-'.Str::lower((string) Str::ulid()),
        'capacity' => 40,
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/spaces/schema?operation=update&recordKey='.$space->getKey())
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('slug'), 'uniqueness_scope'))->toBe('spaces.slug')
        ->and(data_get($fields->get('capacity'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('capacity'), 'normalization.empty_string_at_mutation_layer'))->toBe('null')
        ->and(data_get($fields->get('institutions'), 'relation'))->toBe('institutions')
        ->and(data_get($fields->get('institutions'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('institutions'), 'collection_semantics.submitted_array'))->toBe('replace_relation_sync');
});

it('clears space capacity and institutions through the admin api', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();
    $space = Space::factory()->create([
        'slug' => 'admin-api-space-clear-'.Str::lower((string) Str::ulid()),
        'capacity' => 80,
    ]);
    $space->institutions()->attach($institution);

    Sanctum::actingAs($admin);

    $this->putJson('/api/v1/admin/spaces/'.$space->getRouteKey(), [
        'name' => $space->name,
        'slug' => $space->slug,
        'capacity' => null,
        'institutions' => null,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.capacity', null);

    $space->refresh()->load('institutions');

    expect($space->capacity)->toBeNull()
        ->and($space->institutions)->toHaveCount(0);
});

it('exposes donation channel write schema and can create and update donation channels through the api', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/donation-channels/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'donation-channels')
        ->assertJsonPath('data.schema.resource_key', 'donation-channels')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/donation-channels')
        ->json('data.schema');

    expect(collect($schema['fields'] ?? [])->pluck('name')->all())
        ->toContain('donatable_type', 'donatable_id', 'recipient', 'method', 'status', 'qr', 'clear_qr');

    $createResponse = $this->withHeaders(['Accept' => 'application/json'])->post('/api/v1/admin/donation-channels', [
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'label' => 'Tabung Jumaat API',
        'recipient' => 'Masjid API',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '123456789012',
        'reference_note' => 'Primary donation channel.',
        'status' => 'verified',
        'is_default' => true,
        'qr' => fakeGeneratedImageUpload('admin-api-donation-channel-qr.png', 640, 640),
    ])->assertCreated();

    $donationChannelRouteKey = (string) $createResponse->json('data.record.route_key');
    $donationChannel = DonationChannel::query()->findOrFail($donationChannelRouteKey);

    expect($donationChannel->donatable_type)->toBe('institution')
        ->and($donationChannel->donatable_id)->toBe($institution->getKey())
        ->and($donationChannel->label)->toBe('Tabung Jumaat API')
        ->and($donationChannel->recipient)->toBe('Masjid API')
        ->and($donationChannel->method)->toBe('bank_account')
        ->and($donationChannel->bank_name)->toBe('Maybank')
        ->and($donationChannel->account_number)->toBe('123456789012')
        ->and($donationChannel->duitnow_type)->toBeNull()
        ->and($donationChannel->ewallet_provider)->toBeNull()
        ->and($donationChannel->is_default)->toBeTrue()
        ->and($donationChannel->getMedia('qr'))->toHaveCount(1);

    $this->getJson('/api/v1/admin/donation-channels/schema?operation=update&recordKey='.$donationChannelRouteKey)
        ->assertOk()
        ->assertJsonPath('data.schema.method', 'PUT')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/donation-channels/'.$donationChannelRouteKey)
        ->assertJsonPath('data.schema.defaults.donatable_type', 'institution')
        ->assertJsonPath('data.schema.defaults.donatable_id', (string) $institution->getKey())
        ->assertJsonPath('data.schema.defaults.clear_qr', false);

    $this->putJson('/api/v1/admin/donation-channels/'.$donationChannelRouteKey, [
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'label' => 'Tabung Jumaat API Updated',
        'recipient' => 'Masjid API Updated',
        'method' => 'ewallet',
        'ewallet_provider' => 'tng',
        'ewallet_handle' => '60123456789',
        'ewallet_qr_payload' => 'duitnow://payment/ilmu360',
        'reference_note' => 'Fallback donation channel.',
        'status' => 'inactive',
        'is_default' => false,
        'clear_qr' => true,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.label', 'Tabung Jumaat API Updated')
        ->assertJsonPath('data.record.attributes.method', 'ewallet')
        ->assertJsonPath('data.record.attributes.status', 'inactive');

    $donationChannel->refresh();

    expect($donationChannel->label)->toBe('Tabung Jumaat API Updated')
        ->and($donationChannel->recipient)->toBe('Masjid API Updated')
        ->and($donationChannel->method)->toBe('ewallet')
        ->and($donationChannel->bank_code)->toBeNull()
        ->and($donationChannel->bank_name)->toBeNull()
        ->and($donationChannel->account_number)->toBeNull()
        ->and($donationChannel->ewallet_provider)->toBe('tng')
        ->and($donationChannel->ewallet_handle)->toBe('60123456789')
        ->and($donationChannel->ewallet_qr_payload)->toBe('duitnow://payment/ilmu360')
        ->and($donationChannel->status)->toBe('inactive')
        ->and($donationChannel->is_default)->toBeFalse()
        ->and($donationChannel->getMedia('qr'))->toHaveCount(0);
});

it('surfaces donation channel update semantics through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();
    $donationChannel = DonationChannel::factory()->create([
        'donatable_type' => (string) (new Institution)->getMorphClass(),
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Schema Donation Channel',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'account_number' => '1234567890',
        'status' => 'verified',
    ]);

    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/donation-channels/schema?operation=update&recordKey='.$donationChannel->getKey())
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('donatable_type'), 'accepted_aliases.speakers'))->toBe((string) (new Person)->getMorphClass())
        ->and(data_get($fields->get('method'), 'mutation_semantics'))->toBe('replace_scalar_with_method_partition_reset')
        ->and(data_get($fields->get('method'), 'switch_clears_fields.duitnow'))->toContain('bank_name', 'account_number')
        ->and(data_get($fields->get('label'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('reference_note'), 'normalization.empty_string_at_mutation_layer'))->toBe('null');
});

it('normalizes donation channel owner aliases and clears method-specific strings through the admin api', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();

    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/donation-channels', [
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'label' => 'Alias Donation Channel',
        'recipient' => 'Alias Recipient',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '1234567890',
        'reference_note' => 'Alias note',
        'status' => 'verified',
    ])->assertCreated();

    $donationChannelRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->putJson('/api/v1/admin/donation-channels/'.$donationChannelRouteKey, [
        'donatable_type' => Institution::class,
        'donatable_id' => (string) $institution->getKey(),
        'label' => '',
        'recipient' => 'Alias Recipient',
        'method' => 'duitnow',
        'duitnow_type' => 'mobile',
        'duitnow_value' => '60112233445',
        'reference_note' => '',
        'status' => 'verified',
        'is_default' => false,
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.label', null)
        ->assertJsonPath('data.record.attributes.reference_note', null)
        ->assertJsonPath('data.record.attributes.method', 'duitnow');

    $donationChannel = DonationChannel::query()->findOrFail($donationChannelRouteKey);

    expect($donationChannel->donatable_type)->toBe((string) (new Institution)->getMorphClass())
        ->and($donationChannel->label)->toBeNull()
        ->and($donationChannel->reference_note)->toBeNull()
        ->and($donationChannel->bank_code)->toBeNull()
        ->and($donationChannel->bank_name)->toBeNull()
        ->and($donationChannel->account_number)->toBeNull()
        ->and($donationChannel->duitnow_type)->toBe('mobile')
        ->and($donationChannel->duitnow_value)->toBe('60112233445');
});

it('exposes membership claim review schema and can approve claims through the admin workflow endpoints', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => 'pending',
        ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/membership-applications/'.$claim->getRouteKey().'/review-schema')
        ->assertOk()
        ->assertJsonPath('data.schema.action', 'review_membership_application')
        ->assertJsonPath('data.schema.conditional_rules.0.field', 'granted_role');

    $this->postJson('/api/v1/admin/membership-applications/'.$claim->getRouteKey().'/review', [
        'action' => 'approve',
        'granted_role' => 'admin',
        'reviewer_note' => 'Approved through admin API.',
    ])->assertOk()
        ->assertJsonPath('data.record.id', $claim->getKey())
        ->assertJsonPath('data.record.status', 'approved');

    expect($claim->fresh()?->status->value)->toBe('approved')
        ->and($claim->fresh()?->granted_role)->toBe('admin')
        ->and($claim->fresh()?->reviewer_id)->toBe($admin->getKey())
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue();
});

it('requires a valid granted role when approving a membership claim through the admin api', function () {
    $admin = adminApiUser('super_admin');
    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create(['status' => 'pending']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/membership-applications/'.$claim->getRouteKey().'/review', [
        'action' => 'approve',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['granted_role']);

    expect($claim->fresh()?->status->value)->toBe('pending');
});

it('exposes admin speaker write schema and can create and update speakers through the api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $schema = $this->getJson('/api/v1/admin/people/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'people')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.slug_behavior', 'auto_managed')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/people')
        ->json('data.schema');

    $speakerFields = collect($schema['fields'] ?? [])->pluck('name')->all();

    expect(collect($schema['catalogs'] ?? [])->pluck('field')->all())
        ->toContain('address.country_id')
        ->toContain('address.admin_area_1_id', 'address.admin_area_2_id')
        ->and($speakerFields)->toContain('address.country_id')
        ->and($speakerFields)->toContain('address.admin_area_1_id', 'address.admin_area_2_id')
        ->and($speakerFields)->not->toContain('address.admin_area_3_id')
        ->and($speakerFields)->not->toContain('address.country_code', 'address.country_key')
        ->and(collect($schema['conditional_rules'] ?? [])->pluck('field')->all())->not->toContain('address.country_id');

    $createResponse = $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Created Person',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertCreated();

    $personRouteKey = (string) $createResponse->json('data.record.route_key');
    $person = Person::query()->findOrFail($personRouteKey);

    expect($person->name)->toBe('Admin API Created Person')
        ->and($person->slug)->toBe('admin-api-created-speaker-my')
        ->and($person->status)->toBe('verified')
        ->and($person->allow_public_event_submission)->toBeTrue();

    $this->putJson('/api/v1/admin/people/'.$personRouteKey, [
        'name' => 'Admin API Updated Person',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof_madya'],
        'post_nominal' => ['BA', 'PhD', 'HONS'],
        'status' => 'verified',
        'is_freelance' => true,
        'job_title' => 'Imam',
        'allow_public_event_submission' => true,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Updated Person')
        ->assertJsonPath('data.record.attributes.slug', 'prof-madya-dato-dr-admin-api-updated-speaker-phd-ba-hons-my')
        ->assertJsonPath('data.record.attributes.job_title', 'Imam');
});

it('requires explicit country and still prohibits detailed address fields when creating speakers through the admin api', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Missing Person Country',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'address.country_id',
        ]);

    $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Invalid Person Address',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Alamat Lama',
            'google_maps_url' => 'https://maps.google.com/?q=1,1',
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'address.line1',
            'address.google_maps_url',
        ]);
});

it('returns fresh speaker address data on admin GET requests after updates', function () {
    $this->seed(ScopedMemberRolesSeeder::class);

    $firstFixtures = ensureAdminApiSubdistrictFixtures();
    $secondFixtures = ensureAdminApiSubdistrictFixtures();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Address Freshness Person',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => $firstFixtures['country_id'],
            'admin_area_1_id' => $firstFixtures['admin_area_1_id'],
            'admin_area_2_id' => $firstFixtures['admin_area_2_id'],
        ],
    ])->assertCreated();

    $personRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->getJson('/api/v1/admin/people/'.$personRouteKey)
        ->assertOk()
        ->assertJsonPath('data.record.attributes.address.country_id', $firstFixtures['country_id'])
        ->assertJsonMissingPath('data.record.attributes.address.line1')
        ->assertJsonMissingPath('data.record.attributes.address.google_maps_url')
        ->assertJsonPath('data.record.attributes.address.admin_area_1_id', $firstFixtures['admin_area_1_id'])
        ->assertJsonPath('data.record.attributes.address.admin_area_2_id', $firstFixtures['admin_area_2_id']);

    $this->putJson('/api/v1/admin/people/'.$personRouteKey, [
        'name' => 'Admin API Address Freshness Person',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => $secondFixtures['country_id'],
            'admin_area_1_id' => $secondFixtures['admin_area_1_id'],
            'admin_area_2_id' => $secondFixtures['admin_area_2_id'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.address.country_id', $secondFixtures['country_id'])
        ->assertJsonMissingPath('data.record.attributes.address.line1')
        ->assertJsonMissingPath('data.record.attributes.address.google_maps_url')
        ->assertJsonPath('data.record.attributes.address.admin_area_1_id', $secondFixtures['admin_area_1_id'])
        ->assertJsonPath('data.record.attributes.address.admin_area_2_id', $secondFixtures['admin_area_2_id']);

    $this->getJson('/api/v1/admin/people/'.$personRouteKey)
        ->assertOk()
        ->assertJsonPath('data.record.attributes.address.country_id', $secondFixtures['country_id'])
        ->assertJsonMissingPath('data.record.attributes.address.line1')
        ->assertJsonMissingPath('data.record.attributes.address.google_maps_url')
        ->assertJsonPath('data.record.attributes.address.admin_area_1_id', $secondFixtures['admin_area_1_id'])
        ->assertJsonPath('data.record.attributes.address.admin_area_2_id', $secondFixtures['admin_area_2_id']);

    $this->getJson('/api/v1/admin/people?search=Admin%20API%20Address%20Freshness%20Speaker')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.address.country_id', $secondFixtures['country_id'])
        ->assertJsonMissingPath('data.0.attributes.address.line1')
        ->assertJsonMissingPath('data.0.attributes.address.google_maps_url')
        ->assertJsonPath('data.0.attributes.address.admin_area_1_id', $secondFixtures['admin_area_1_id'])
        ->assertJsonPath('data.0.attributes.address.admin_area_2_id', $secondFixtures['admin_area_2_id']);
});

it('surfaces speaker update semantics and collection rules through the admin api schema', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Person Schema Surface',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertCreated();

    $schema = $this->getJson('/api/v1/admin/people/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');
    $qualificationItemFields = collect(data_get($fields->get('qualifications'), 'item_schema.fields', []))->keyBy('name');

    expect(data_get($fields->get('address'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address'), 'mutation_semantics'))->toBe('deep_merge_when_present_visible_fields_only')
        ->and(data_get($fields->get('address'), 'clear_semantics.empty_object'))->toBe('invalid_without_country')
        ->and(data_get($fields->get('address'), 'prohibited_nested_fields'))->toContain('line1', 'google_maps_url')
        ->and(data_get($fields->get('address.country_id'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address.country_id'), 'required_when_parent_present_on_update'))->toBeTrue()
        ->and(data_get($fields->get('honorific'), 'collection_semantics.submitted_array'))->toBe('replace_collection')
        ->and(data_get($fields->get('qualifications'), 'collection_semantics.empty_array'))->toBe('clear_collection')
        ->and($qualificationItemFields->keys()->all())->toContain('institution', 'degree', 'field', 'year')
        ->and(data_get($fields->get('language_ids'), 'collection_semantics.submitted_array'))->toBe('replace_relation_sync')
        ->and(data_get($fields->get('contactMethods'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to'))->toBe('x')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation'))->toBeFalse();
});

it('replaces speaker collections and still requires an explicit country when mutating address data through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $languageMalay = Language::where('code', 'ms')->first() ?? Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'name_native' => 'Bahasa Melayu',
        'dir' => 'ltr',
    ]);

    $languageEnglish = Language::where('code', 'en')->first() ?? Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'name_native' => 'English',
        'dir' => 'ltr',
    ]);

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/people', [
        'name' => 'Admin API Person Collections',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => true,
        'job_title' => 'Imam',
        'honorific' => ['dato'],
        'qualifications' => [[
            'institution' => 'Universiti Lama',
            'degree' => 'BA',
            'field' => 'Fiqh',
            'year' => '2010',
        ]],
        'language_ids' => [$languageMalay->id],
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
        'contactMethods' => [[
            'type' => 'phone',
            'value' => '0311111111',
            'purpose' => 'general',
            'is_public' => true,
        ]],
        'social_media' => [[
            'platform' => 'website',
            'url' => 'https://example.test/speakers/admin-api-speaker-collections',
        ], [
            'platform' => 'instagram',
            'handle' => 'asal_penceramah',
        ]],
    ])->assertCreated();

    $personRouteKey = (string) $createResponse->json('data.record.route_key');
    $person = withGlobalOwnerContext(
        fn (): Person => Person::query()->with(['contactMethods', 'socialProfiles', 'languages'])->findOrFail($personRouteKey),
    );
    $originalContactIds = $person->contactMethods->modelKeys();
    $originalSocialMediaIds = $person->socialProfiles->modelKeys();

    $this->putJson('/api/v1/admin/people/'.$personRouteKey, [
        'name' => 'Admin API Person Collections',
        'gender' => 'male',
        'status' => 'verified',
        'address' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['address.country_id']);

    $this->putJson('/api/v1/admin/people/'.$personRouteKey, [
        'name' => 'Admin API Person Collections Updated',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => false,
        'honorific' => ['datuk'],
        'qualifications' => [[
            'institution' => 'Universiti Baharu',
            'degree' => 'PhD',
            'field' => 'Aqidah',
            'year' => '2024',
        ]],
        'language_ids' => [$languageEnglish->id],
        'contactMethods' => [[
            'type' => 'whatsapp',
            'value' => '+60123456789',
            'purpose' => 'support',
            'is_public' => false,
        ]],
        'social_media' => [[
            'platform' => 'facebook',
            'url' => 'https://facebook.com/admin-api-speaker-collections-updated',
        ]],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Person Collections Updated')
        ->assertJsonPath('data.record.attributes.honorific.0', 'datuk')
        ->assertJsonPath('data.record.attributes.contacts.0.type', 'whatsapp')
        ->assertJsonPath('data.record.attributes.social_media.0.platform', 'facebook')
        ->assertJsonPath('data.record.attributes.social_media.0.handle', 'admin-api-speaker-collections-updated')
        ->assertJsonPath('data.record.attributes.social_media.0.url', 'https://facebook.com/admin-api-speaker-collections-updated');

    $person = withGlobalOwnerContext(
        fn (): Person => $person->refresh()->load(['contactMethods', 'socialProfiles', 'languages']),
    );

    expect($person->languages->pluck('id')->all())->toEqual([(int) $languageEnglish->id])
        ->and($person->contactMethods)->toHaveCount(1)
        ->and($person->contactMethods->first()?->getRawOriginal('type'))->toBe('whatsapp')
        ->and($person->contactMethods->first()?->getRawOriginal('purpose'))->toBe('support')
        ->and(collect($person->contactMethods->modelKeys())->intersect($originalContactIds)->all())->toBe([])
        ->and($person->socialProfiles)->toHaveCount(1)
        ->and($person->socialProfiles->first()?->getRawOriginal('platform'))->toBe('facebook')
        ->and($person->socialProfiles->first()?->handle)->toBe('admin-api-speaker-collections-updated')
        ->and($person->socialProfiles->first()?->url)->toBe('https://facebook.com/admin-api-speaker-collections-updated')
        ->and(collect($person->socialProfiles->modelKeys())->intersect($originalSocialMediaIds)->all())->toBe([]);

    $this->putJson('/api/v1/admin/people/'.$personRouteKey, [
        'name' => 'Admin API Person Collections Updated',
        'gender' => 'male',
        'status' => 'verified',
        'language_ids' => null,
        'contactMethods' => null,
        'social_media' => [],
    ])->assertOk();

    $person = withGlobalOwnerContext(
        fn (): Person => $person->refresh()->load(['contactMethods', 'socialProfiles', 'languages']),
    );

    expect($person->languages)->toHaveCount(0)
        ->and($person->contactMethods)->toHaveCount(0)
        ->and($person->socialProfiles)->toHaveCount(0);
});

it('allows sparse venue address updates without resending the existing country through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/venues', [
        'name' => 'Admin API Sparse Venue Country',
        'type' => 'dewan',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Alamat Asal',
        ],
    ])->assertCreated();

    $venueRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->putJson('/api/v1/admin/venues/'.$venueRouteKey, [
        'name' => 'Admin API Sparse Venue Country',
        'type' => 'dewan',
        'status' => 'verified',
        'address' => [
            'line1' => 'Alamat Terkini Tanpa Country',
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.address.country_id', ensureAdminApiMalaysiaCountryExists())
        ->assertJsonPath('data.record.attributes.address.line1', 'Alamat Terkini Tanpa Country');

    expect(Venue::query()->findOrFail($venueRouteKey)->primaryAddress()?->country_id)->toBe(ensureAdminApiMalaysiaCountryExists())
        ->and(Venue::query()->findOrFail($venueRouteKey)->primaryAddress()?->line1)->toBe('Alamat Terkini Tanpa Country');
});

it('exposes admin institution write schema and can create and update institutions through the api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institutionSchema = $this->getJson('/api/v1/admin/institutions/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'institutions')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/institutions')
        ->json('data.schema');

    expect(collect($institutionSchema['fields'] ?? [])->pluck('name')->all())
        ->toContain('address.country_id')
        ->and(collect($institutionSchema['fields'] ?? [])->pluck('name')->all())->not->toContain('address.country_code', 'address.country_key')
        ->and(collect($institutionSchema['conditional_rules'] ?? [])->pluck('field')->all())->not->toContain('address.country_id');

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Institution',
        'nickname' => 'API Surau',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');
    $institution = Institution::query()->findOrFail($institutionRouteKey);

    expect($institution->display_name)->toBe('Admin API Institution (API Surau)')
        ->and($institution->status)->toBe('verified')
        ->and($institution->allow_public_event_submission)->toBeTrue();

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Updated',
        'nickname' => 'API Masjid',
        'type' => 'masjid',
        'status' => 'pending',
        'allow_public_event_submission' => true,
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Institution Updated')
        ->assertJsonPath('data.record.attributes.nickname', 'API Masjid');
});

it('preserves institution address line1 when sparse map fields are updated through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Sparse Institution',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Alamat Asal Institusi',
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Sparse Institution',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'google_maps_url' => 'https://example.com/maps/institution',
            'latitude' => 3.123456,
            'longitude' => 101.654321,
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.address.line1', 'Alamat Asal Institusi')
        ->assertJsonPath('data.record.attributes.address.google_maps_url', fn (string $url): bool => str_contains($url, 'google.com/maps/search'))
        ->assertJsonPath('data.record.attributes.address.latitude', fn (mixed $latitude): bool => (float) $latitude === 3.123456)
        ->assertJsonPath('data.record.attributes.address.longitude', fn (mixed $longitude): bool => (float) $longitude === 101.654321);

    $institution = Institution::query()->findOrFail($institutionRouteKey);

    expect($institution->primaryAddress())->not->toBeNull()
        ->and($institution->primaryAddress()?->line1)->toBe('Alamat Asal Institusi')
        ->and($institution->primaryAddress()?->google_maps_url)->toContain('google.com/maps/search')
        ->and((float) $institution->primaryAddress()?->latitude)->toBe(3.123456)
        ->and((float) $institution->primaryAddress()?->longitude)->toBe(101.654321);
});

it('surfaces institution update semantics and nested item schemas through the admin api schema', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Institution Schema Surface',
        'nickname' => 'Schema Surface',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertCreated();

    $schema = $this->getJson('/api/v1/admin/institutions/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');
    $contactItemFields = collect(data_get($fields->get('contactMethods'), 'item_schema.fields', []))->keyBy('name');
    $socialMediaItemFields = collect(data_get($fields->get('social_media'), 'item_schema.fields', []))->keyBy('name');

    expect(data_get($fields->get('address'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address'), 'mutation_semantics'))->toBe('deep_merge_when_present')
        ->and(data_get($fields->get('address'), 'clear_semantics.empty_object'))->toBe('preserve_existing_when_record_has_address')
        ->and(data_get($fields->get('address.country_id'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address.country_id'), 'required_on_update'))->toBeFalse()
        ->and(data_get($fields->get('nickname'), 'clear_semantics.explicit_null'))->toBe('preserve_existing')
        ->and(data_get($fields->get('nickname'), 'normalization.empty_string_at_mutation_layer'))->toBe('null')
        ->and(data_get($fields->get('contactMethods'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('contactMethods'), 'collection_semantics.submitted_array'))->toBe('replace_collection')
        ->and($contactItemFields->keys()->all())->toContain('type', 'value', 'purpose', 'is_public', 'sort_order')
        ->and(data_get($contactItemFields->get('value'), 'used_for_types'))->toContain('phone', 'whatsapp', 'email')
        ->and(data_get($fields->get('social_media'), 'collection_semantics.empty_array'))->toBe('clear_collection')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to'))->toBe('x')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation'))->toBeFalse()
        ->and(data_get($fields->get('social_media'), 'input_normalization.canonical_storage.identifier_field'))->toBe('handle')
        ->and($socialMediaItemFields->keys()->all())->toContain('platform', 'handle', 'url', 'sort_order')
        ->and(data_get($fields->get('social_media'), 'item_schema.at_least_one_of'))->toBe(['handle', 'url']);
});

it('preserves institution nickname on null-like input through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Institution Nickname',
        'nickname' => 'API Surau',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Nickname',
        'nickname' => null,
        'type' => 'masjid',
        'status' => 'verified',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.nickname', 'API Surau');

    expect(Institution::query()->findOrFail($institutionRouteKey)->nickname)->toBe('API Surau');

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Nickname',
        'nickname' => '',
        'type' => 'masjid',
        'status' => 'verified',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.nickname', 'API Surau');

    expect(Institution::query()->findOrFail($institutionRouteKey)->nickname)->toBe('API Surau');
});

it('treats empty institution address objects as a no-op when the record already has an address', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Institution Empty Address',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Alamat Tidak Patut Hilang',
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Empty Address',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.address.country_id', ensureAdminApiMalaysiaCountryExists())
        ->assertJsonPath('data.record.attributes.address.line1', 'Alamat Tidak Patut Hilang');

    expect(Institution::query()->findOrFail($institutionRouteKey)->primaryAddress()?->line1)->toBe('Alamat Tidak Patut Hilang');
});

it('replaces institution contacts and social media collections and canonicalizes handle urls through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Institution Collections',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
        'contactMethods' => [
            [
                'type' => 'phone',
                'value' => '0311111111',
                'purpose' => 'general',
                'is_public' => true,
            ],
            [
                'type' => 'email',
                'value' => 'asal@example.test',
                'purpose' => 'admin',
                'is_public' => false,
            ],
        ],
        'social_media' => [
            [
                'platform' => 'website',
                'url' => 'https://example.test/institutions/admin-api-institution-collections',
            ],
            [
                'platform' => 'instagram',
                'handle' => 'asal_handle',
            ],
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');
    $institution = withGlobalOwnerContext(
        fn (): Institution => Institution::query()->with(['contactMethods', 'socialProfiles'])->findOrFail($institutionRouteKey),
    );
    $originalContactIds = $institution->contactMethods->modelKeys();
    $originalSocialMediaIds = $institution->socialProfiles->modelKeys();

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Collections Updated',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
        'contactMethods' => [
            [
                'type' => 'whatsapp',
                'value' => '+60123456789',
                'purpose' => 'support',
                'is_public' => false,
            ],
        ],
        'social_media' => [
            [
                'platform' => 'facebook',
                'url' => 'https://facebook.com/admin-api-institution-collections-updated',
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Institution Collections Updated')
        ->assertJsonPath('data.record.attributes.contacts.0.type', 'whatsapp')
        ->assertJsonPath('data.record.attributes.social_media.0.platform', 'facebook')
        ->assertJsonPath('data.record.attributes.social_media.0.handle', 'admin-api-institution-collections-updated')
        ->assertJsonPath('data.record.attributes.social_media.0.url', 'https://facebook.com/admin-api-institution-collections-updated');

    $institution = withGlobalOwnerContext(
        fn (): Institution => $institution->refresh()->load(['contactMethods', 'socialProfiles']),
    );

    $replacedContactIds = $institution->contactMethods->modelKeys();
    $replacedSocialMediaIds = $institution->socialProfiles->modelKeys();

    expect($institution->contactMethods)->toHaveCount(1)
        ->and($institution->contactMethods->first()?->getRawOriginal('type'))->toBe('whatsapp')
        ->and($institution->contactMethods->first()?->getRawOriginal('purpose'))->toBe('support')
        ->and(collect($replacedContactIds)->intersect($originalContactIds)->all())->toBe([])
        ->and($institution->socialProfiles)->toHaveCount(1)
        ->and($institution->socialProfiles->first()?->getRawOriginal('platform'))->toBe('facebook')
        ->and($institution->socialProfiles->first()?->handle)->toBe('admin-api-institution-collections-updated')
        ->and($institution->socialProfiles->first()?->url)->toBe('https://facebook.com/admin-api-institution-collections-updated')
        ->and(collect($replacedSocialMediaIds)->intersect($originalSocialMediaIds)->all())->toBe([]);

    $this->putJson('/api/v1/admin/institutions/'.$institutionRouteKey, [
        'name' => 'Admin API Institution Collections Updated',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
        ],
        'contactMethods' => null,
        'social_media' => [],
    ])->assertOk();

    $institution = withGlobalOwnerContext(
        fn (): Institution => $institution->refresh()->load(['contactMethods', 'socialProfiles']),
    );

    expect($institution->contactMethods)->toHaveCount(0)
        ->and($institution->socialProfiles)->toHaveCount(0);
});

it('exposes institution contacts and social_media in admin-get-record response', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/institutions', [
        'name' => 'Admin API Get Record Contacts Institution',
        'type' => 'masjid',
        'status' => 'verified',
        'address' => ['country_id' => ensureAdminApiMalaysiaCountryExists()],
        'contactMethods' => [
            ['type' => 'email', 'value' => 'get-record@example.test', 'purpose' => 'general', 'is_public' => true],
        ],
        'social_media' => [
            ['platform' => 'facebook', 'url' => 'https://facebook.com/get-record-institution'],
        ],
    ])->assertCreated();

    $institutionRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->getJson('/api/v1/admin/institutions/'.$institutionRouteKey)
        ->assertOk()
        ->assertJsonPath('data.record.attributes.contacts.0.type', 'email')
        ->assertJsonPath('data.record.attributes.contacts.0.value', 'get-record@example.test')
        ->assertJsonPath('data.record.attributes.social_media.0.platform', 'facebook')
        ->assertJsonPath('data.record.attributes.social_media.0.handle', 'get-record-institution')
        ->assertJsonPath('data.record.attributes.social_media.0.url', 'https://facebook.com/get-record-institution');
});

it('requires a record key when requesting an admin update schema', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/institutions/schema?operation=update')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recordKey'])
        ->assertJsonPath('error.code', 'validation_error');
});

it('exposes admin venue write schema and can create and update venues through the api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/venues/meta')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'venues')
        ->assertJsonPath('data.resource.write_support.schema', true)
        ->assertJsonPath('data.resource.write_support.store', true)
        ->assertJsonPath('data.resource.write_support.update', true)
        ->assertJsonPath('data.resource.api_routes.collection', '/api/v1/admin/venues')
        ->assertJsonPath('data.resource.api_routes.schema', '/api/v1/admin/venues/schema');

    $this->getJson('/api/v1/admin/venues/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'venues')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/venues')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.defaults.type', 'dewan')
        ->assertJsonPath('data.schema.defaults.status', 'verified')
        ->assertJsonPath('data.schema.catalogs.0.field', 'address.country_id');

    $createResponse = $this->postJson('/api/v1/admin/venues', [
        'name' => 'Admin API Venue',
        'type' => 'dewan',
        'status' => 'verified',
        'facilities' => ['parking', 'oku'],
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Dewan Serbaguna API',
        ],
        'contactMethods' => [
            [
                'type' => 'phone',
                'value' => '0312345678',
                'purpose' => 'general',
                'is_public' => true,
            ],
        ],
        'social_media' => [
            [
                'platform' => 'website',
                'url' => 'https://example.com/venues/admin-api-venue',
            ],
        ],
    ])->assertCreated();

    $venueRouteKey = (string) $createResponse->json('data.record.route_key');
    $venue = withGlobalOwnerContext(
        fn (): Venue => Venue::query()->with(['addresses', 'contactMethods', 'socialProfiles'])->findOrFail($venueRouteKey),
    );

    expect($venue->name)->toBe('Admin API Venue')
        ->and($venue->slug)->toBe('admin-api-venue-my')
        ->and($venue->status)->toBe('verified')
        ->and((string) $venue->status)->toBeIn(['verified', 'pending'])
        ->and($venue->facilities->load('facilityType')->pluck('facilityType.code')->all())->toEqualCanonicalizing([
            'oku',
            'parking',
        ])
        ->and($venue->primaryAddress()?->country_id)->toBe(ensureAdminApiMalaysiaCountryExists())
        ->and($venue->contactMethods)->toHaveCount(1)
        ->and($venue->contactMethods->first()?->value)->toBe('0312345678')
        ->and($venue->socialProfiles)->toHaveCount(1)
        ->and($venue->socialProfiles->first()?->platform)->toBe('website');

    $this->putJson('/api/v1/admin/venues/'.$venueRouteKey, [
        'name' => 'Admin API Venue Updated',
        'type' => 'auditorium',
        'status' => 'inactive',
        'facilities' => ['women_section', 'ablution_area'],
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Auditorium API Baharu',
        ],
        'contactMethods' => [
            [
                'type' => 'whatsapp',
                'value' => '60123456789',
                'purpose' => 'support',
                'is_public' => false,
            ],
        ],
        'social_media' => [
            [
                'platform' => 'facebook',
                'url' => 'https://facebook.com/admin-api-venue-updated',
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Venue Updated')
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-venue-updated-my')
        ->assertJsonPath('data.record.attributes.venue_type', 'auditorium')
        ->assertJsonPath('data.record.attributes.status', 'inactive');

    $venue = withGlobalOwnerContext(
        fn (): Venue => $venue->refresh()->load(['addresses', 'contactMethods', 'socialProfiles']),
    );

    expect($venue->name)->toBe('Admin API Venue Updated')
        ->and($venue->slug)->toBe('admin-api-venue-updated-my')
        ->and($venue->getRawOriginal('venue_type'))->toBe('auditorium')
        ->and((string) $venue->status)->toBe('inactive')
        ->and($venue->facilities->load('facilityType')->pluck('facilityType.code')->all())->toEqualCanonicalizing([
            'women_section',
            'ablution_area',
        ])
        ->and($venue->primaryAddress()?->line1)->toBe('Auditorium API Baharu')
        ->and($venue->contactMethods)->toHaveCount(1)
        ->and($venue->contactMethods->first()?->getRawOriginal('type'))->toBe('whatsapp')
        ->and($venue->socialProfiles)->toHaveCount(1)
        ->and($venue->socialProfiles->first()?->getRawOriginal('platform'))->toBe('facebook');

    $this->putJson('/api/v1/admin/venues/'.$venueRouteKey, [
        'name' => 'Admin API Venue Updated',
        'type' => 'auditorium',
        'status' => 'inactive',
        'address' => [
            'google_maps_url' => 'https://example.com/venues/admin-api-venue-updated',
            'latitude' => 3.147,
            'longitude' => 101.694,
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.address.line1', 'Auditorium API Baharu')
        ->assertJsonPath('data.record.attributes.address.google_maps_url', fn (string $url): bool => str_contains($url, 'google.com/maps/search'))
        ->assertJsonPath('data.record.attributes.address.latitude', fn (mixed $latitude): bool => (float) $latitude === 3.147)
        ->assertJsonPath('data.record.attributes.address.longitude', fn (mixed $longitude): bool => (float) $longitude === 101.694);
});

it('surfaces venue update semantics and destructive empty-address behavior through the admin api schema', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/venues', [
        'name' => 'Admin API Venue Schema Surface',
        'type' => 'dewan',
        'status' => 'verified',
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Dewan Schema',
        ],
    ])->assertCreated();

    $schema = $this->getJson('/api/v1/admin/venues/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('name'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('type'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('status'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('address'), 'clear_semantics.empty_object'))->toBe('delete_existing_address')
        ->and(data_get($fields->get('address.country_id'), 'required_on_update'))->toBeFalse()
        ->and(data_get($fields->get('facilities'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('facilities'), 'input_normalization.kind'))->toBe('facility_codes_to_relation')
        ->and(data_get($fields->get('contactMethods'), 'collection_semantics.submitted_array'))->toBe('replace_collection')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to'))->toBe('x')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation'))->toBeFalse();
});

it('replaces venue collections and deletes the address on an empty object through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/venues', [
        'name' => 'Admin API Venue Collections',
        'type' => 'dewan',
        'status' => 'verified',
        'facilities' => ['parking', 'oku'],
        'address' => [
            'country_id' => ensureAdminApiMalaysiaCountryExists(),
            'line1' => 'Dewan Koleksi',
        ],
        'contactMethods' => [[
            'type' => 'phone',
            'value' => '0312345678',
            'purpose' => 'general',
            'is_public' => true,
        ]],
        'social_media' => [[
            'platform' => 'website',
            'url' => 'https://example.com/venues/admin-api-venue-collections',
        ], [
            'platform' => 'instagram',
            'handle' => 'asal_venue',
        ]],
    ])->assertCreated();

    $venueRouteKey = (string) $createResponse->json('data.record.route_key');
    $venue = withGlobalOwnerContext(
        fn (): Venue => Venue::query()->with(['contactMethods', 'socialProfiles'])->findOrFail($venueRouteKey),
    );
    $originalContactIds = $venue->contactMethods->modelKeys();
    $originalSocialMediaIds = $venue->socialProfiles->modelKeys();

    $this->putJson('/api/v1/admin/venues/'.$venueRouteKey, [
        'facilities' => ['women_section'],
        'contactMethods' => [[
            'type' => 'whatsapp',
            'value' => '+60123456789',
            'purpose' => 'support',
            'is_public' => false,
        ]],
        'social_media' => [[
            'platform' => 'facebook',
            'url' => 'https://facebook.com/admin-api-venue-collections-updated',
        ]],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.contacts.0.type', 'whatsapp')
        ->assertJsonPath('data.record.attributes.social_media.0.platform', 'facebook')
        ->assertJsonPath('data.record.attributes.social_media.0.handle', 'admin-api-venue-collections-updated')
        ->assertJsonPath('data.record.attributes.social_media.0.url', 'https://facebook.com/admin-api-venue-collections-updated');

    $venue = withGlobalOwnerContext(
        fn (): Venue => $venue->refresh()->load(['contactMethods', 'socialProfiles']),
    );

    expect($venue->facilities->load('facilityType')->pluck('facilityType.code')->all())->toBe([
        'women_section',
    ])
        ->and($venue->contactMethods)->toHaveCount(1)
        ->and($venue->contactMethods->first()?->getRawOriginal('type'))->toBe('whatsapp')
        ->and(collect($venue->contactMethods->modelKeys())->intersect($originalContactIds)->all())->toBe([])
        ->and($venue->socialProfiles)->toHaveCount(1)
        ->and($venue->socialProfiles->first()?->getRawOriginal('platform'))->toBe('facebook')
        ->and($venue->socialProfiles->first()?->handle)->toBe('admin-api-venue-collections-updated')
        ->and($venue->socialProfiles->first()?->url)->toBe('https://facebook.com/admin-api-venue-collections-updated')
        ->and(collect($venue->socialProfiles->modelKeys())->intersect($originalSocialMediaIds)->all())->toBe([]);

    $this->putJson('/api/v1/admin/venues/'.$venueRouteKey, [
        'address' => [],
        'facilities' => null,
        'contactMethods' => null,
        'social_media' => [],
    ])->assertOk();

    $venue = withGlobalOwnerContext(
        fn (): Venue => $venue->refresh()->load(['contactMethods', 'socialProfiles']),
    );

    expect($venue->primaryAddress())->toBeNull()
        ->and($venue->facilities->load('facilityType')->pluck('facilityType.code')->all())->toBe([])
        ->and($venue->contactMethods)->toHaveCount(0)
        ->and($venue->socialProfiles)->toHaveCount(0);
});

it('lists admin geography catalogs and exposes catalog metadata through admin write schemas', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $fixtures = ensureAdminApiSubdistrictFixtures();

    $this->getJson('/api/v1/admin/catalogs/countries')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $fixtures['country_id'],
            'label' => 'Malaysia',
            'iso2' => 'MY',
            'key' => 'malaysia',
        ]);

    $this->getJson('/api/v1/admin/catalogs/states?country_id='.$fixtures['country_id'])
        ->assertOk()
        ->assertJsonFragment([
            'id' => $fixtures['state_id'],
        ]);

    $this->getJson('/api/v1/admin/catalogs/admin-area-level-1?state_id='.$fixtures['state_id'])
        ->assertOk()
        ->assertJsonFragment([
            'id' => $fixtures['admin_area_1_id'],
        ]);

    $this->getJson('/api/v1/admin/catalogs/admin-area-level-2?admin_area_1_id='.$fixtures['admin_area_1_id'])
        ->assertOk()
        ->assertJsonFragment([
            'id' => $fixtures['admin_area_2_id'],
            'label' => $fixtures['subdistrict_name'],
        ]);

    $institutionSchema = $this->getJson('/api/v1/admin/institutions/schema?operation=create')
        ->assertOk()
        ->json('data.schema.catalogs');

    $institutionCatalogs = collect(is_array($institutionSchema) ? $institutionSchema : [])->keyBy('field');

    expect($institutionCatalogs->get('address.country_id')['endpoint'] ?? null)->toBe('/api/v1/admin/catalogs/countries')
        ->and($institutionCatalogs->get('address.admin_area_1_id')['query']['country_id'] ?? null)->toBe('{address.country_id}')
        ->and($institutionCatalogs->get('address.admin_area_2_id')['query']['admin_area_1_id'] ?? null)->toBe('{address.admin_area_1_id}')
        ->and($institutionCatalogs->has('address.admin_area_3_id'))->toBeFalse();

    $addressAreaSchema = $this->getJson('/api/v1/admin/address-areas/schema?operation=create')
        ->assertOk()
        ->json('data.schema.catalogs');

    $addressAreaCatalogs = collect(is_array($addressAreaSchema) ? $addressAreaSchema : [])->keyBy('field');

    expect($addressAreaCatalogs->get('country_id')['endpoint'] ?? null)->toBe('/api/v1/admin/catalogs/countries');
});

it('exposes admin reference write schema and can create and update references through the api', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/references/meta')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'references')
        ->assertJsonPath('data.resource.write_support.schema', true)
        ->assertJsonPath('data.resource.write_support.store', true)
        ->assertJsonPath('data.resource.write_support.update', true)
        ->assertJsonPath('data.resource.api_routes.collection', '/api/v1/admin/references')
        ->assertJsonPath('data.resource.api_routes.schema', '/api/v1/admin/references/schema');

    $this->getJson('/api/v1/admin/references/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'references')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/references')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.defaults.type', 'book');

    $createResponse = $this->postJson('/api/v1/admin/references', [
        'title' => 'Admin API Reference',
        'author' => 'Admin API Author',
        'type' => 'book',
        'publication_year' => '2024',
        'publisher' => 'Admin API Press',
        'description' => 'Admin API reference description.',
        'is_canonical' => true,
        'status' => 'verified',
        'social_media' => [
            [
                'platform' => 'website',
                'url' => 'https://example.com/references/admin-api-reference',
            ],
        ],
    ])->assertCreated();

    $referenceRouteKey = (string) $createResponse->json('data.record.route_key');
    $reference = withGlobalOwnerContext(
        fn (): Reference => Reference::query()->with('socialProfiles')->where('slug', $referenceRouteKey)->firstOrFail(),
    );
    $referenceId = (string) $reference->getKey();

    expect($reference->title)->toBe('Admin API Reference')
        ->and($reference->slug)->toBe('admin-api-reference')
        ->and($reference->is_canonical)->toBeTrue()
        ->and($reference->status)->toBe('verified')
        ->and((string) $reference->status)->toBeIn(['verified', 'pending'])
        ->and($reference->socialProfiles)->toHaveCount(1)
        ->and($reference->socialProfiles->first()?->platform)->toBe('website');

    $this->getJson('/api/v1/admin/references/'.$referenceRouteKey)
        ->assertOk()
        ->assertJsonPath('data.record.route_key', $referenceRouteKey)
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-reference');

    $this->getJson('/api/v1/admin/references/'.$referenceId)->assertNotFound();

    $this->getJson('/api/v1/admin/references/schema?operation=update&recordKey='.$referenceRouteKey)
        ->assertOk()
        ->assertJsonPath('data.schema.method', 'PUT')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/references/'.$referenceRouteKey)
        ->assertJsonPath('data.schema.defaults.title', 'Admin API Reference');

    $this->putJson('/api/v1/admin/references/'.$referenceRouteKey, [
        'title' => 'Admin API Reference Updated',
        'author' => 'Admin API Editor',
        'type' => 'article',
        'publication_year' => null,
        'publisher' => 'Admin API Review',
        'description' => 'Updated admin API reference description.',
        'is_canonical' => false,
        'status' => 'inactive',
        'social_media' => [
            [
                'platform' => 'youtube',
                'url' => 'https://youtube.com/watch?v=admin-api-reference-updated',
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.title', 'Admin API Reference Updated')
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-reference-updated')
        ->assertJsonPath('data.record.attributes.type', 'article');

    $reference = withGlobalOwnerContext(
        fn (): Reference => $reference->refresh()->load('socialProfiles'),
    );

    expect($reference->title)->toBe('Admin API Reference Updated')
        ->and($reference->slug)->toBe('admin-api-reference-updated')
        ->and($reference->type)->toBe('article')
        ->and($reference->year)->toBeNull()
        ->and($reference->publisher)->toBe('Admin API Review')
        ->and($reference->is_canonical)->toBeFalse()
        ->and((string) $reference->status)->toBe('inactive')
        ->and($reference->socialProfiles)->toHaveCount(1)
        ->and($reference->socialProfiles->first()?->platform)->toBe('youtube');

    $this->getJson('/api/v1/admin/references/'.$reference->getRouteKey())
        ->assertOk()
        ->assertJsonPath('data.record.route_key', 'admin-api-reference-updated')
        ->assertJsonPath('data.record.attributes.slug', 'admin-api-reference-updated');
});

it('surfaces reference update semantics and social-media normalization rules through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/references', [
        'title' => 'Admin API Reference Schema Surface',
        'type' => 'book',
        'status' => 'verified',
    ])->assertCreated();

    $schema = $this->getJson('/api/v1/admin/references/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('author'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('author'), 'normalization.empty_string_at_mutation_layer'))->toBe('null')
        ->and(data_get($fields->get('publication_year'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('publisher'), 'clear_semantics.explicit_null'))->toBe('clear_to_null')
        ->and(data_get($fields->get('social_media'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('social_media'), 'collection_semantics.submitted_array'))->toBe('replace_collection')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to'))->toBe('x')
        ->and(data_get($fields->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation'))->toBeFalse();
});

it('clears normalized reference scalars and replaces canonicalized social media through the admin api', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/references', [
        'title' => 'Admin API Reference Collections',
        'author' => 'Penulis Lama',
        'type' => 'book',
        'publication_year' => '2024',
        'publisher' => 'Penerbit Lama',
        'status' => 'verified',
        'social_media' => [[
            'platform' => 'website',
            'url' => 'https://example.com/references/admin-api-reference-collections',
        ], [
            'platform' => 'instagram',
            'handle' => 'asal_reference',
        ]],
    ])->assertCreated();

    $referenceRouteKey = (string) $createResponse->json('data.record.route_key');
    $reference = withGlobalOwnerContext(
        fn (): Reference => Reference::query()->with('socialProfiles')->where('slug', $referenceRouteKey)->firstOrFail(),
    );
    $originalSocialMediaIds = $reference->socialProfiles->modelKeys();

    $this->putJson('/api/v1/admin/references/'.$referenceRouteKey, [
        'title' => 'Admin API Reference Collections Updated',
        'author' => null,
        'type' => 'book',
        'publication_year' => '',
        'publisher' => '',
        'status' => 'verified',
        'social_media' => [[
            'platform' => 'youtube',
            'url' => 'https://youtube.com/@admin-api-reference-collections-updated',
        ]],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.title', 'Admin API Reference Collections Updated')
        ->assertJsonPath('data.record.attributes.author', null)
        ->assertJsonPath('data.record.attributes.publication_year', null)
        ->assertJsonPath('data.record.attributes.publisher', null)
        ->assertJsonPath('data.record.attributes.social_media.0.platform', 'youtube')
        ->assertJsonPath('data.record.attributes.social_media.0.handle', 'admin-api-reference-collections-updated')
        ->assertJsonPath('data.record.attributes.social_media.0.url', 'https://youtube.com/@admin-api-reference-collections-updated');

    $reference = withGlobalOwnerContext(
        fn (): Reference => $reference->refresh()->load('socialProfiles'),
    );

    $updatedReferenceRouteKey = (string) $reference->getRouteKey();

    expect($reference->author)->toBeNull()
        ->and($reference->year)->toBeNull()
        ->and($reference->publisher)->toBeNull()
        ->and($reference->socialProfiles)->toHaveCount(1)
        ->and($reference->socialProfiles->first()?->getRawOriginal('platform'))->toBe('youtube')
        ->and($reference->socialProfiles->first()?->handle)->toBe('admin-api-reference-collections-updated')
        ->and($reference->socialProfiles->first()?->url)->toBe('https://youtube.com/@admin-api-reference-collections-updated')
        ->and(collect($reference->socialProfiles->modelKeys())->intersect($originalSocialMediaIds)->all())->toBe([]);

    $this->putJson('/api/v1/admin/references/'.$updatedReferenceRouteKey, [
        'title' => 'Admin API Reference Collections Updated',
        'type' => 'book',
        'status' => 'verified',
        'social_media' => null,
    ])->assertOk();

    expect(withGlobalOwnerContext(fn (): Reference => $reference->fresh()->load('socialProfiles'))->socialProfiles)->toHaveCount(0);
});

it('exposes admin address-area write schema and can create and update address areas through the api', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $fixtures = ensureAdminApiSubdistrictFixtures();

    $this->getJson('/api/v1/admin/address-areas/meta')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'address-areas')
        ->assertJsonPath('data.resource.write_support.schema', true)
        ->assertJsonPath('data.resource.write_support.store', true)
        ->assertJsonPath('data.resource.write_support.update', true)
        ->assertJsonPath('data.resource.api_routes.collection', '/api/v1/admin/address-areas')
        ->assertJsonPath('data.resource.api_routes.schema', '/api/v1/admin/address-areas/schema');

    $this->getJson('/api/v1/admin/address-areas/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'address-areas')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/address-areas')
        ->assertJsonPath('data.schema.content_type', 'application/json');

    $createResponse = $this->postJson('/api/v1/admin/address-areas', [
        'country_id' => $fixtures['country_id'],
        'parent_id' => $fixtures['admin_area_1_id'],
        'type' => 'subdistrict',
        'level' => 3,
        'name' => '  Admin API Created Address Area  ',
    ])->assertCreated();

    $addressAreaRouteKey = (string) $createResponse->json('data.record.route_key');

    $this->getJson('/api/v1/admin/address-areas/schema?operation=update&recordKey='.$addressAreaRouteKey)
        ->assertOk()
        ->assertJsonPath('data.schema.method', 'PUT')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/address-areas/'.$addressAreaRouteKey)
        ->assertJsonPath('data.schema.defaults.country_id', $fixtures['country_id'])
        ->assertJsonPath('data.schema.defaults.parent_id', $fixtures['admin_area_1_id'])
        ->assertJsonPath('data.schema.defaults.type', 'subdistrict')
        ->assertJsonPath('data.schema.defaults.level', 3)
        ->assertJsonPath('data.schema.defaults.name', 'Admin API Created Address Area');

    $this->putJson('/api/v1/admin/address-areas/'.$addressAreaRouteKey, [
        'country_id' => $fixtures['country_id'],
        'parent_id' => $fixtures['area_tree_root_id'],
        'type' => 'district',
        'level' => 2,
        'name' => '  Admin API Updated Address Area  ',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.name', 'Admin API Updated Address Area')
        ->assertJsonPath('data.record.attributes.parent_id', $fixtures['area_tree_root_id'])
        ->assertJsonPath('data.record.attributes.type', 'district')
        ->assertJsonPath('data.record.attributes.level', 2);
});

it('surfaces address-area update semantics through the admin api schema', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $fixtures = ensureAdminApiSubdistrictFixtures();

    $createResponse = $this->postJson('/api/v1/admin/address-areas', [
        'country_id' => $fixtures['country_id'],
        'parent_id' => $fixtures['admin_area_1_id'],
        'type' => 'subdistrict',
        'level' => 3,
        'name' => 'Admin API Schema Address Area',
    ])->assertCreated();

    $schema = $this->getJson('/api/v1/admin/address-areas/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');

    expect(data_get($fields->get('country_id'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('parent_id'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('level'), 'input_type'))->toBe('integer')
        ->and(data_get($fields->get('type'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('name'), 'normalization.trim'))->toBeTrue();
});

it('clamps admin collection per_page values to the supported maximum', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    Person::factory()->count(110)->create();

    $this->getJson('/api/v1/admin/people?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', 100)
        ->assertJsonCount(100, 'data');
});

it('requires country_id when creating address areas through the admin api', function () {
    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/address-areas', [
        'parent_id' => null,
        'type' => 'state',
        'level' => 1,
        'name' => 'Admin API Invalid Address Area',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['country_id']);
});

it('exposes admin event write schema and can create and update events through the api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Discipline');
    $sourceTag = adminApiEventTerm('source', 'Admin API Source');

    $this->getJson('/api/v1/admin/events/meta')
        ->assertOk()
        ->assertJsonPath('data.resource.key', 'events')
        ->assertJsonPath('data.resource.write_support.schema', true)
        ->assertJsonPath('data.resource.write_support.store', true)
        ->assertJsonPath('data.resource.write_support.update', true)
        ->assertJsonPath('data.resource.api_routes.collection', '/api/v1/admin/events')
        ->assertJsonPath('data.resource.api_routes.schema', '/api/v1/admin/events/schema');

    $this->getJson('/api/v1/admin/events/schema?operation=create')
        ->assertOk()
        ->assertJsonPath('data.schema.resource_key', 'events')
        ->assertJsonPath('data.schema.method', 'POST')
        ->assertJsonPath('data.schema.endpoint', '/api/v1/admin/events')
        ->assertJsonPath('data.schema.content_type', 'multipart/form-data')
        ->assertJsonPath('data.schema.defaults.live_url', null);

    $createResponse = $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ]))->assertCreated();

    $eventRouteKey = (string) $createResponse->json('data.record.route_key');
    $event = Event::query()
        ->with(['references', 'series', 'classifications', 'keyPeople'])
        ->findOrFail($eventRouteKey);

    expect($event->title)->toBe('Admin API Event Created')
        ->and($event->live_url)->toBeNull()
        ->and($event->starts_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-05-20 20:00')
        ->and($event->accessPolicy?->registration_required)->toBeTrue()
        ->and($event->resolvedRegistrationMode())->toBe(PackageRegistrationMode::Required)
        ->and($event->references->pluck('id')->all())->toContain($reference->getKey())
        ->and($event->series->pluck('id')->all())->toContain($series->getKey())
        ->and($event->classifications->pluck('event_term_id')->all())->toContain($domainTag->getKey(), $disciplineTag->getKey())
        ->and($event->keyPeople)->toHaveCount(2);

    $this->putJson('/api/v1/admin/events/'.$eventRouteKey, adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'title' => 'Admin API Event Updated',
        'event_date' => '2026-06-01',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'custom_time' => null,
        'end_time' => '22:30',
        'live_url' => 'https://youtube.com/watch?v=admin-api-event-live',
        'primary_organizer_id' => $person->getKey(),
        'institution_id' => null,
        'references' => [],
        'series' => [],
        'domain_tags' => [],
        'discipline_tags' => [],
        'source_tags' => [(string) $sourceTag->getKey()],
        'persons' => [],
        'other_key_people' => [],
        'registration_required' => false,
    ]))->assertOk()
        ->assertJsonPath('data.record.attributes.title', 'Admin API Event Updated')
        ->assertJsonPath('data.record.attributes.live_url', 'https://youtube.com/watch?v=admin-api-event-live');

    $event->refresh()->load(['references', 'series', 'classifications', 'keyPeople']);

    expect($event->title)->toBe('Admin API Event Updated')
        ->and($event->live_url)->toBe('https://youtube.com/watch?v=admin-api-event-live')
        ->and($event->starts_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-06-01 20:00')
        ->and($event->accessPolicy?->registration_required)->toBeFalse()
        ->and($event->references)->toHaveCount(0)
        ->and($event->series)->toHaveCount(0)
        ->and($event->classifications->pluck('event_term_id')->all())->toContain($sourceTag->getKey())
        ->and($event->classifications->pluck('event_term_id')->all())->not->toContain($domainTag->getKey(), $disciplineTag->getKey())
        ->and($event->keyPeople)->toHaveCount(0)
        ->and($event->slug)->toContain($person->slug);
});

it('surfaces event update semantics and sparse relation rules through the admin api schema', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Schema Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Schema Discipline');

    $createResponse = $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ]))->assertCreated();

    $schema = $this->getJson('/api/v1/admin/events/schema?operation=update&recordKey='.$createResponse->json('data.record.route_key'))
        ->assertOk()
        ->json('data.schema');

    $fields = collect($schema['fields'] ?? [])->keyBy('name');
    $otherKeyPeopleFields = collect(data_get($fields->get('other_key_people'), 'item_schema.fields', []))->keyBy('name');

    expect(data_get($fields->get('title'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('event_date'), 'required'))->toBeFalse()
        ->and(data_get($fields->get('event_category_ids'), 'collection_semantics.empty_array'))->toBe('invalid_minimum_size')
        ->and(data_get($fields->get('languages'), 'collection_semantics.submitted_array'))->toBe('replace_relation_sync')
        ->and(data_get($fields->get('references'), 'collection_semantics.explicit_null'))->toBe('clear_collection')
        ->and(data_get($fields->get('domain_tags'), 'taxonomy_code'))->toBe('domain')
        ->and(data_get($fields->get('primary_organizer_id'), 'accepted_models'))->toBe([Institution::class, Person::class])
        ->and(data_get($fields->get('persons'), 'collection_semantics.submitted_array'))->toBe('replace_speaker_subset_and_rebuild_key_people')
        ->and(data_get($fields->get('persons'), 'collection_semantics.item_ids_preserved'))->toBeFalse()
        ->and(data_get($fields->get('other_key_people'), 'collection_semantics.ordering'))->toBe('payload_order_sets_order_column_after_speakers')
        ->and($otherKeyPeopleFields->keys()->all())->toContain('role_code', 'involveable_type', 'involveable_id', 'display_name', 'visibility', 'notes')
        ->and(data_get($fields->get('registration_mode'), 'lock_behavior.when_event_has_registrations'))->toBe('retain_current_value');
});

it('supports sparse event updates while replacing submitted relation collections through the admin api', function () {
    ensureAdminApiMalaysiaCountryExists();

    $languageMalay = Language::where('code', 'ms')->first() ?? Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'name_native' => 'Bahasa Melayu',
        'dir' => 'ltr',
    ]);

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $secondPerson = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Sparse Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Sparse Discipline');
    $sourceTag = adminApiEventTerm('source', 'Admin API Sparse Source');

    $createResponse = $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'languages' => [$languageMalay->id],
        'source_tags' => [(string) $sourceTag->getKey()],
    ]))->assertCreated();

    $eventRouteKey = (string) $createResponse->json('data.record.route_key');
    $event = Event::query()
        ->with(['references', 'series', 'classifications', 'keyPeople', 'languages'])
        ->findOrFail($eventRouteKey);
    $originalKeyPeopleIds = $event->keyPeople->modelKeys();

    $this->putJson('/api/v1/admin/events/'.$eventRouteKey, [
        'live_url' => null,
        'references' => null,
        'series' => [],
        'languages' => [],
        'domain_tags' => [],
        'persons' => [(string) $person->getKey(), (string) $secondPerson->getKey()],
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.title', 'Admin API Event Created')
        ->assertJsonPath('data.record.attributes.live_url', null);

    $event->refresh()->load(['references', 'series', 'classifications', 'keyPeople', 'languages']);

    expect($event->title)->toBe('Admin API Event Created')
        ->and($event->live_url)->toBeNull()
        ->and($event->references)->toHaveCount(0)
        ->and($event->series)->toHaveCount(0)
        ->and($event->languages)->toHaveCount(0)
        ->and($event->classifications->pluck('event_term_id')->all())->toContain($disciplineTag->getKey(), $sourceTag->getKey())
        ->and($event->classifications->pluck('event_term_id')->all())->not->toContain($domainTag->getKey())
        ->and($event->keyPeople)->toHaveCount(3)
        ->and($event->keyPeople->where('role_code', EventKeyPersonRole::Speaker->value)->pluck('involveable_id')->all())->toEqualCanonicalizing([
            (string) $person->getKey(),
            (string) $secondPerson->getKey(),
        ])
        ->and($event->keyPeople->where('role_code', EventKeyPersonRole::Moderator->value)->count())->toBe(1)
        ->and(collect($event->keyPeople->modelKeys())->intersect($originalKeyPeopleIds)->all())->toBe([]);
});

it('clears event poster when clear_poster is submitted as a form-style boolean', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Poster Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Poster Discipline');

    $createResponse = $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'poster' => fakeGeneratedImageUpload('admin-api-event-poster.png', 1200, 1500),
    ]))->assertCreated()
        ->assertJsonPath('data.record.attributes.has_poster', true);

    $eventRouteKey = (string) $createResponse->json('data.record.route_key');
    $event = Event::query()->findOrFail($eventRouteKey);

    expect($event->getMedia('poster'))->toHaveCount(1);

    $this->putJson('/api/v1/admin/events/'.$eventRouteKey, [
        'clear_poster' => '1',
    ])->assertOk()
        ->assertJsonPath('data.record.attributes.has_poster', false)
        ->assertJsonPath('data.record.attributes.poster_url', null);

    $event->refresh();

    expect($event->getMedia('poster'))->toHaveCount(0);

    $this->getJson('/api/v1/admin/events/schema?operation=update&recordKey='.$eventRouteKey)
        ->assertOk()
        ->assertJsonPath('data.schema.current_media.poster', []);
});

it('rejects admin event writes that omit required speakers for speaker-led event types', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Person Validation Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Person Validation Discipline');

    $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'persons' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['persons']);
});

it('rejects admin event writes with organizer ids that do not resolve to institutions or speakers', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Organizer Validation Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Organizer Validation Discipline');
    $venue = Venue::factory()->create();

    $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'primary_organizer_id' => (string) $venue->getKey(),
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['primary_organizer_id']);
});

it('rejects admin event writes with conflicting location selections', function () {
    ensureAdminApiMalaysiaCountryExists();

    $admin = adminApiUser('super_admin');
    Sanctum::actingAs($admin);

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $otherInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminApiEventTerm('domain', 'Admin API Location Validation Domain');
    $disciplineTag = adminApiEventTerm('discipline', 'Admin API Location Validation Discipline');
    $venue = Venue::factory()->create();
    $space = Space::factory()->create([
        'slug' => 'admin-api-space-conflict-'.Str::lower((string) Str::ulid()),
    ]);
    $otherInstitution->spaces()->attach($space);

    $this->postJson('/api/v1/admin/events', adminApiEventPayload([
        'institution' => $institution,
        'speaker' => $person,
        'reference' => $reference,
        'series' => $series,
        'domain_tag' => $domainTag,
        'discipline_tag' => $disciplineTag,
    ], [
        'venue_id' => (string) $venue->getKey(),
        'space_ids' => [(string) $space->getKey()],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['institution_id', 'venue_id', 'space_ids']);
});

function adminApiEventTerm(string $taxonomyCode, string $name): EventTerm
{
    $taxonomy = EventTaxonomy::query()->firstOrCreate(
        ['code' => $taxonomyCode],
        [
            'name' => ucfirst($taxonomyCode),
            'description' => null,
            'is_hierarchical' => false,
            'is_active' => true,
        ],
    );

    return EventTerm::query()->firstOrCreate(
        [
            'event_taxonomy_id' => $taxonomy->getKey(),
            'code' => Str::slug($name),
        ],
        [
            'name' => $name,
            'sort_order' => 0,
            'is_active' => true,
        ],
    );
}

function ensureAdminApiMalaysiaCountryExists(): string
{
    return (string) ensureTestMalaysiaCountry()->getKey();
}

/**
 * @return array{
 *     country_id: string,
 *     state_id: string,
 *     admin_area_1_id: string,
 *     admin_area_2_id: string,
 *     subdistrict_name: string
 * }
 */
function ensureAdminApiSubdistrictFixtures(): array
{
    $suffix = Str::lower(Str::random(8));
    $subdistrictName = 'Admin API Mukim '.$suffix;
    $geo = createTestPackageGeography(
        'Admin API Negeri '.$suffix,
        'Admin API Daerah '.$suffix,
        $subdistrictName,
    );

    AddressAreaStateLink::query()->firstOrCreate([
        'address_area_id' => $geo['area_tree_root']->getKey(),
        'state_id' => $geo['state']->getKey(),
    ]);

    return [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'admin_area_1_id' => (string) $geo['district']->getKey(),
        'admin_area_2_id' => (string) $geo['subdistrict']->getKey(),
        'subdistrict_name' => $subdistrictName,
        'area_tree_root_id' => (string) $geo['area_tree_root']->getKey(),
    ];
}

function adminApiUser(string $role): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * @param  array{
 *     institution: Institution,
 *     speaker: Person,
 *     reference: Reference,
 *     series: Series,
 *     domain_tag: EventTerm,
 *     discipline_tag: EventTerm
 * }  $fixtures
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminApiEventPayload(array $fixtures, array $overrides = []): array
{
    return array_replace([
        'title' => 'Admin API Event Created',
        'event_date' => '2026-05-20',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:00',
        'end_time' => '22:00',
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Hybrid->value,
        'visibility' => EventVisibility::Public->value,
        'event_url' => 'https://example.com/events/admin-api-event-created',
        'live_url' => null,
        'recording_url' => 'https://example.com/recordings/admin-api-event-created',
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'is_muslim_only' => true,
        'event_category_ids' => [eventCategoryId('other')],
        'domain_tags' => [(string) $fixtures['domain_tag']->getKey()],
        'discipline_tags' => [(string) $fixtures['discipline_tag']->getKey()],
        'source_tags' => [],
        'issue_tags' => [],
        'references' => [(string) $fixtures['reference']->getKey()],
        'primary_organizer_id' => (string) $fixtures['institution']->getKey(),
        'institution_id' => (string) $fixtures['institution']->getKey(),
        'series' => [(string) $fixtures['series']->getKey()],
        'persons' => [(string) $fixtures['speaker']->getKey()],
        'other_key_people' => [
            [
                'role_code' => 'moderator',
                'display_name' => 'Admin API Moderator',
                'visibility' => 'public',
                'notes' => 'Will host the session.',
            ],
        ],
        'registration_required' => true,
        'registration_mode' => RegistrationScope::Event->value,
        'status' => 'draft',
    ], $overrides);
}

it('batch-creates admin resource records and returns per-row results', function () {
    $admin = adminApiUser('super_admin');
    ensureAdminApiMalaysiaCountryExists();

    Sanctum::actingAs($admin);

    $person1 = Person::factory()->create([
        'name' => 'Batch API Person One',
        'status' => 'verified',
    ]);

    $person2 = Person::factory()->create([
        'name' => 'Batch API Person Two',
        'status' => 'verified',
    ]);

    $response = $this->postJson('/api/v1/admin/people/batch', [
        'items' => [
            [
                'external_row_id' => 'row-A',
                'payload' => [
                    'name' => 'Batch Created Person Alpha',
                    'gender' => 'male',
                    'status' => 'verified',
                    'address' => ['country_id' => ensureAdminApiMalaysiaCountryExists()],
                ],
            ],
            [
                'external_row_id' => 'row-B',
                'payload' => [
                    'name' => 'Batch Created Person Beta',
                    'gender' => 'female',
                    'status' => 'verified',
                    'address' => ['country_id' => ensureAdminApiMalaysiaCountryExists()],
                ],
            ],
        ],
    ]);

    $response->assertCreated();

    expect($response->json('data.summary.total'))->toBe(2)
        ->and($response->json('data.summary.created'))->toBe(2)
        ->and($response->json('data.summary.validation_failed'))->toBe(0)
        ->and($response->json('data.summary.errors'))->toBe(0);

    $results = $response->json('data.results');

    expect($results)->toHaveCount(2)
        ->and($results[0]['status'])->toBe('created')
        ->and($results[0]['external_row_id'])->toBe('row-A')
        ->and($results[1]['status'])->toBe('created')
        ->and($results[1]['external_row_id'])->toBe('row-B');

    $this->assertDatabaseHas('persons', ['name' => 'Batch Created Person Alpha']);
    $this->assertDatabaseHas('persons', ['name' => 'Batch Created Person Beta']);
});

it('batch-creates records and returns per-row validation errors without rolling back successes', function () {
    $admin = adminApiUser('super_admin');
    ensureAdminApiMalaysiaCountryExists();

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/people/batch', [
        'items' => [
            [
                'external_row_id' => 'row-ok',
                'payload' => [
                    'name' => 'Batch Valid Person',
                    'gender' => 'male',
                    'status' => 'verified',
                    'address' => ['country_id' => ensureAdminApiMalaysiaCountryExists()],
                ],
            ],
            [
                'external_row_id' => 'row-fail',
                'payload' => [
                    // Missing required name
                    'gender' => 'male',
                    'status' => 'verified',
                ],
            ],
        ],
    ]);

    $response->assertCreated();

    expect($response->json('data.summary.total'))->toBe(2)
        ->and($response->json('data.summary.created'))->toBe(1)
        ->and($response->json('data.summary.validation_failed'))->toBe(1);

    $results = collect($response->json('data.results'));

    $okResult = $results->firstWhere('external_row_id', 'row-ok');
    $failResult = $results->firstWhere('external_row_id', 'row-fail');

    expect($okResult['status'])->toBe('created')
        ->and($failResult['status'])->toBe('validation_failed')
        ->and($failResult['errors'])->toBeArray();

    $this->assertDatabaseHas('persons', ['name' => 'Batch Valid Person']);
});

it('batch-creates records with validate_only and returns previews without persisting', function () {
    $admin = adminApiUser('super_admin');
    ensureAdminApiMalaysiaCountryExists();

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/people/batch?validate_only=1', [
        'items' => [
            [
                'payload' => [
                    'name' => 'Dry Run Person',
                    'gender' => 'male',
                    'status' => 'verified',
                    'address' => ['country_id' => ensureAdminApiMalaysiaCountryExists()],
                ],
            ],
        ],
    ]);

    $response->assertOk();

    expect($response->json('data.validate_only'))->toBeTrue()
        ->and($response->json('data.results.0.status'))->toBe('preview');

    $this->assertDatabaseMissing('persons', ['name' => 'Dry Run Person']);
});

it('batch-updates admin resource records and returns per-row results', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $person1 = Person::factory()->create([
        'name' => 'Batch Update Person One',
        'status' => 'pending',
    ]);

    $person2 = Person::factory()->create([
        'name' => 'Batch Update Person Two',
        'status' => 'pending',
    ]);

    $response = $this->putJson('/api/v1/admin/people/batch', [
        'items' => [
            [
                'record_key' => (string) $person1->getKey(),
                'external_row_id' => 'update-A',
                'payload' => [
                    'name' => 'Batch Updated Person One',
                    'gender' => 'male',
                    'status' => 'verified',
                ],
            ],
            [
                'record_key' => (string) $person2->getKey(),
                'external_row_id' => 'update-B',
                'payload' => [
                    'name' => 'Batch Updated Person Two',
                    'gender' => 'female',
                    'status' => 'verified',
                ],
            ],
        ],
    ]);

    $response->assertOk();

    expect($response->json('data.summary.total'))->toBe(2)
        ->and($response->json('data.summary.updated'))->toBe(2)
        ->and($response->json('data.summary.validation_failed'))->toBe(0)
        ->and($response->json('data.summary.not_found'))->toBe(0);

    $results = $response->json('data.results');

    expect($results)->toHaveCount(2)
        ->and($results[0]['status'])->toBe('updated')
        ->and($results[0]['external_row_id'])->toBe('update-A')
        ->and($results[1]['status'])->toBe('updated')
        ->and($results[1]['external_row_id'])->toBe('update-B');

    $this->assertDatabaseHas('persons', ['name' => 'Batch Updated Person One']);
    $this->assertDatabaseHas('persons', ['name' => 'Batch Updated Person Two']);
});

it('batch-updates returns not_found for missing record keys', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/v1/admin/people/batch', [
        'items' => [
            [
                'record_key' => '00000000-0000-0000-0000-000000000000',
                'payload' => [
                    'name' => 'Ghost Person',
                    'gender' => 'male',
                    'status' => 'verified',
                ],
            ],
        ],
    ]);

    $response->assertOk();

    expect($response->json('data.summary.not_found'))->toBe(1)
        ->and($response->json('data.results.0.status'))->toBe('not_found');
});

it('batch-updates returns error for items missing record_key', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/v1/admin/people/batch', [
        'items' => [
            [
                'payload' => [
                    'name' => 'No Key Person',
                    'gender' => 'male',
                    'status' => 'verified',
                ],
            ],
        ],
    ]);

    $response->assertOk();

    expect($response->json('data.results.0.status'))->toBe('error');
});

it('rejects batch operations on non-writable resources', function () {
    $admin = adminApiUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/users/batch', [
        'items' => [['payload' => ['name' => 'Test']]],
    ])->assertNotFound();
});

it('rejects unauthenticated batch create requests', function () {
    $this->postJson('/api/v1/admin/people/batch', [
        'items' => [['payload' => ['name' => 'Test']]],
    ])->assertUnauthorized();
});

it('rejects unauthenticated batch update requests', function () {
    $this->putJson('/api/v1/admin/people/batch', [
        'items' => [['record_key' => 'some-key', 'payload' => ['name' => 'Test']]],
    ])->assertUnauthorized();
});
