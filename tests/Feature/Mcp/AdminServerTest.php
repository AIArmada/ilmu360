<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Signals\Models\SignalEvent;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventAgeGroup;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\RegistrationScope;
use App\Mcp\Prompts\DocumentationToolRoutingPrompt;
use App\Mcp\Resources\Docs\McpGuideResource;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Admin\AdminBatchCreateEventsTool;
use App\Mcp\Tools\Admin\AdminBatchCreateRecordsTool;
use App\Mcp\Tools\Admin\AdminBatchUpdateEventsTool;
use App\Mcp\Tools\Admin\AdminBatchUpdateRecordsTool;
use App\Mcp\Tools\Admin\AdminCreateEventTool;
use App\Mcp\Tools\Admin\AdminCreateGitHubIssueTool;
use App\Mcp\Tools\Admin\AdminCreateRecordTool;
use App\Mcp\Tools\Admin\AdminDocumentationFetchTool;
use App\Mcp\Tools\Admin\AdminDocumentationSearchTool;
use App\Mcp\Tools\Admin\AdminGetContributionRequestReviewSchemaTool;
use App\Mcp\Tools\Admin\AdminGetEventModerationSchemaTool;
use App\Mcp\Tools\Admin\AdminGetMembershipApplicationReviewSchemaTool;
use App\Mcp\Tools\Admin\AdminGetRecordActionsTool;
use App\Mcp\Tools\Admin\AdminGetRecordTool;
use App\Mcp\Tools\Admin\AdminGetReportTriageSchemaTool;
use App\Mcp\Tools\Admin\AdminGetResourceMetaTool;
use App\Mcp\Tools\Admin\AdminGetWriteSchemaTool;
use App\Mcp\Tools\Admin\AdminListRecordsTool;
use App\Mcp\Tools\Admin\AdminListRelatedRecordsTool;
use App\Mcp\Tools\Admin\AdminListResourcesTool;
use App\Mcp\Tools\Admin\AdminModerateEventTool;
use App\Mcp\Tools\Admin\AdminReviewContributionRequestTool;
use App\Mcp\Tools\Admin\AdminReviewMembershipApplicationTool;
use App\Mcp\Tools\Admin\AdminSearchEventsTool;
use App\Mcp\Tools\Admin\AdminTriageReportTool;
use App\Mcp\Tools\Admin\AdminUpdateEventTool;
use App\Mcp\Tools\Admin\AdminUpdateRecordTool;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\ModerationReview;
use App\Models\PassportUser;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Series;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\Signals\SignalsTracker;
use App\Support\GitHub\GitHubIssueReportContract;
use App\Support\Mcp\McpDocumentationPreflight;
use App\Support\Mcp\McpTokenManager;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

it('lists accessible admin resources for admin users through the MCP server', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminListResourcesTool::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data.resources')
            ->where('data.resources.5.key', 'donation-channels')
            ->where('data.resources.6.key', 'events')
            ->where('data.resources.8.key', 'institutions')
            ->where('data.resources.10.key', 'references')
            ->where('data.resources.11.key', 'reports')
            ->where('data.resources.15.key', 'speakers')
            ->where('data.resources.0.key', fn (string $key): bool => filled($key))
            ->missing('data.resources.0.resource_class')
            ->etc());
});

it('can request verbose admin resource metadata through the MCP resource list tool', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminListResourcesTool::class, [
            'verbose' => true,
            'writable_only' => true,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data.resources', 12)
            ->where('data.resources.11.key', 'address-areas')
            ->where('data.resources.0.resource_class', fn (string $resourceClass): bool => str_contains($resourceClass, 'Resource'))
            ->etc());
});

it('allows viewer-role admins to use read tools', function () {
    $viewer = adminMcpUser('viewer');

    AdminServer::actingAs($viewer)
        ->tool(AdminListResourcesTool::class)
        ->assertOk()
        ->assertHasNoErrors();
});

it('hides write tools when the authenticated user has no writable admin access', function () {
    $viewer = User::factory()->create();

    AdminServer::actingAs($viewer)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'speakers',
            'operation' => 'create',
        ])
        ->assertHasErrors(['Tool [admin-get-write-schema] not found.']);
});

it('matches richer public search behavior for speakers, institutions, and references through admin MCP list records', function () {
    $admin = adminMcpUser('super_admin');

    $matchingSpeaker = Speaker::factory()->create([
        'name' => 'Admin MCP Speaker Match',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
    ]);
    $otherSpeaker = Speaker::factory()->create([
        'name' => 'Admin MCP Speaker Other',
        'status' => 'verified',
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($matchingSpeaker);
    app(SpeakerSearchService::class)->syncSpeakerRecord($otherSpeaker);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'speakers',
            'search' => 'syeikhul maqari',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'speakers')
            ->where('meta.pagination.total', 1)
            ->where('data.0.id', (string) $matchingSpeaker->getKey())
            ->etc());

    $matchingInstitution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);
    Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'institutions',
            'search' => 'Hidayh',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'institutions')
            ->where('meta.pagination.total', 1)
            ->where('data.0.id', (string) $matchingInstitution->getKey())
            ->etc());

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

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'references',
            'search' => 'tajwid adab',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'references')
            ->where('meta.pagination.total', 1)
            ->where('data.0.id', (string) $matchingReference->getKey())
            ->etc());
});

it('reviews membership applications through the admin MCP workflow tool', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create();
    $applicant = User::factory()->create();
    $application = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $applicant->getKey(),
            'status' => 'pending',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminReviewMembershipApplicationTool::class, [
            'record_key' => $application->getKey(),
            'action' => 'approve',
            'granted_role' => 'admin',
            'reviewer_note' => 'Approved through admin MCP.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'membership-applications')
            ->where('data.record.route_key', $application->getRouteKey())
            ->where('data.record.attributes.status', 'approved')
            ->where('data.record.attributes.granted_role', 'admin')
            ->etc());

    expect($application->fresh()?->status->value)->toBe('approved')
        ->and($application->fresh()?->granted_role)->toBe('admin')
        ->and($application->fresh()?->reviewer_id)->toBe($admin->getKey())
        ->and($institution->fresh()->members()->whereKey($applicant->getKey())->exists())->toBeTrue();
});

it('triages reports through the admin MCP workflow tool', function () {
    $admin = adminMcpUser('moderator');
    $report = Report::factory()->create([
        'status' => 'open',
        'handled_by' => null,
        'resolution_note' => null,
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminTriageReportTool::class, [
            'record_key' => $report->getKey(),
            'action' => 'resolve',
            'resolution_note' => 'Handled through admin MCP.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'reports')
            ->where('data.record.route_key', $report->getRouteKey())
            ->where('data.record.attributes.status', 'resolved')
            ->where('data.record.attributes.resolution_note', 'Handled through admin MCP.')
            ->etc());

    $report->refresh();

    expect($report->status)->toBe('resolved')
        ->and($report->handled_by)->toBe($admin->getKey())
        ->and($report->resolution_note)->toBe('Handled through admin MCP.');
});

it('exposes report write schema and creates and updates reports through the admin MCP server', function () {
    $admin = adminMcpUser('moderator');
    $reporter = User::factory()->create();
    $event = Event::factory()->create();
    $reference = Reference::factory()->verified()->create();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'reports',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'reports')
            ->where('data.schema.resource_key', 'reports')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'reports')
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'evidence'), 'mcp_upload.shape') === 'array<file_descriptor>'
                && collect($fields)
                    ->pluck('name')
                    ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'clear_'))
                    ->isEmpty())
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'reports',
            'payload' => [
                'entity_type' => 'event',
                'entity_id' => (string) $event->getKey(),
                'category' => 'wrong_info',
                'description' => 'Created through admin MCP.',
                'status' => 'open',
                'reporter_id' => (string) $reporter->getKey(),
                'evidence' => [
                    adminMcpImageDescriptor('admin-mcp-report-evidence.png'),
                ],
            ],
        ])
        ->assertOk();

    $report = Report::query()->where('description', 'Created through admin MCP.')->firstOrFail();
    $reportRouteKey = (string) $report->getKey();

    expect($report->entity_type)->toBe('event')
        ->and($report->entity_id)->toBe($event->getKey())
        ->and($report->category)->toBe('wrong_info')
        ->and($report->reporter_id)->toBe($reporter->getKey())
        ->and($report->getMedia('evidence'))->toHaveCount(1);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'reports',
            'record_key' => $reportRouteKey,
            'payload' => [
                'entity_type' => 'reference',
                'entity_id' => (string) $reference->getKey(),
                'category' => 'fake_reference',
                'description' => 'Updated through admin MCP.',
                'status' => 'resolved',
                'reporter_id' => (string) $reporter->getKey(),
                'handled_by' => (string) $admin->getKey(),
                'resolution_note' => 'Resolved through admin MCP.',
                'evidence' => [
                    adminMcpImageDescriptor('admin-mcp-report-evidence-updated.png'),
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.entity_type', 'reference')
            ->where('data.record.attributes.category', 'fake_reference')
            ->where('data.record.attributes.status', 'resolved')
            ->where('data.record.attributes.resolution_note', 'Resolved through admin MCP.')
            ->etc());

    $report->refresh();

    expect($report->entity_type)->toBe('reference')
        ->and($report->entity_id)->toBe($reference->getKey())
        ->and($report->category)->toBe('fake_reference')
        ->and($report->status)->toBe('resolved')
        ->and($report->handled_by)->toBe($admin->getKey())
        ->and($report->resolution_note)->toBe('Resolved through admin MCP.')
        ->and($report->getMedia('evidence'))->toHaveCount(1);
});

it('returns resource metadata, record listings, and record detail for speakers', function () {
    $admin = adminMcpUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Admin MCP Speaker',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetResourceMetaTool::class, [
            'resource_key' => 'speakers',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'speakers')
            ->where('data.resource.api_routes.collection', '/api/v1/admin/speakers')
            ->where('data.resource.api_routes.schema', '/api/v1/admin/speakers/schema')
            ->where('data.resource.mcp_tools.list_records.tool', 'admin-list-records')
            ->where('data.resource.mcp_tools.list_records.arguments.resource_key', 'speakers')
            ->where('data.resource.mcp_tools.list_records.arguments.filters', 'object')
            ->where('data.resource.mcp_tools.get_record_actions.tool', 'admin-get-record-actions')
            ->where('data.resource.mcp_tools.get_record_actions.arguments.record_key', 'record')
            ->where('data.resource.mcp_tools.create.arguments.validate_only', false)
            ->where('data.resource.mcp_tools.update.arguments.validate_only', false)
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'speakers',
            'search' => 'Admin MCP Speaker',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.0.id', $speaker->getKey())
            ->where('data.0.title', 'Admin MCP Speaker')
            ->where('meta.resource.key', 'speakers')
            ->where('meta.pagination.page', 1)
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speaker->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.route_key', $speaker->getRouteKey())
            ->where('data.record.attributes.name', 'Admin MCP Speaker')
            ->etc());
});

it('redacts tracked property write keys in admin MCP payloads', function () {
    $admin = adminMcpUser('super_admin');
    $trackedProperty = app(SignalsTracker::class)->defaultTrackedProperty();

    expect($trackedProperty)->not->toBeNull();

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'tracked-properties',
            'search' => (string) $trackedProperty?->name,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'tracked-properties')
            ->where('data.0.id', (string) $trackedProperty?->getKey())
            ->where('data.0.attributes.write_key', 'present')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetRecordTool::class, [
            'resource_key' => 'tracked-properties',
            'record_key' => (string) $trackedProperty?->getRouteKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.route_key', (string) $trackedProperty?->getRouteKey())
            ->where('data.record.attributes.write_key', 'present')
            ->etc());
});

it('returns focused next-step actions for admin records through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $event = Event::factory()->create([
        'title' => 'Admin MCP Actionable Event',
        'status' => 'pending',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetRecordActionsTool::class, [
            'resource_key' => 'events',
            'record_key' => $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.record.route_key', $event->getRouteKey())
            ->where('data.focus_actions.recommended_keys.0', 'get_event_moderation_schema')
            ->where('data.focus_actions.actions', fn ($actions): bool => array_diff(
                ['get_record', 'get_update_schema', 'update_record', 'get_event_moderation_schema', 'moderate_event'],
                collect($actions)->pluck('key')->all(),
            ) === [])
            ->where('data.focus_actions.actions', fn ($actions): bool => data_get(collect($actions)->firstWhere('key', 'get_event_moderation_schema'), 'tool') === 'admin-get-event-moderation-schema')
            ->where('data.focus_actions.actions', fn ($actions): bool => data_get(collect($actions)->firstWhere('key', 'moderate_event'), 'tool') === 'admin-moderate-event')
            ->where('data.focus_actions.actions', fn ($actions): bool => collect(data_get(collect($actions)->firstWhere('key', 'moderate_event'), 'requires', []))->contains('get_event_moderation_schema'))
            ->where('data.focus_actions.actions', fn ($actions): bool => collect(data_get(collect($actions)->firstWhere('key', 'moderate_event'), 'schema.available_actions', []))
                ->pluck('key')
                ->contains('approve'))
            ->etc());
});

it('lists related records through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $relatedTitle = 'Nested MCP Event '.Str::ulid();
    $speaker = Speaker::factory()->create([
        'name' => 'Nested MCP Speaker',
    ]);
    $event = Event::factory()->create([
        'title' => $relatedTitle,
    ]);

    $event->speakers()->attach($speaker);

    AdminServer::actingAs($admin)
        ->tool(AdminGetResourceMetaTool::class, [
            'resource_key' => 'speakers',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.api_routes.related_collection', '/api/v1/admin/speakers/record/relations/relation')
            ->where('data.resource.mcp_tools.list_related_records.tool', 'admin-list-related-records')
            ->where('data.resource.mcp_tools.list_related_records.arguments.resource_key', 'speakers')
            ->where('data.resource.mcp_tools.list_related_records.arguments.record_key', 'record')
            ->where('data.resource.mcp_tools.list_related_records.arguments.relation', 'relation')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRelatedRecordsTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speaker->getKey(),
            'relation' => 'events',
            'search' => $relatedTitle,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.0.route_key', $event->getRouteKey())
            ->where('data.0.title', $relatedTitle)
            ->where('meta.resource.key', 'speakers')
            ->where('meta.parent_record.route_key', $speaker->getRouteKey())
            ->where('meta.relation.name', 'events')
            ->where('meta.relation.related_resource.key', 'events')
            ->etc());
});

it('exposes timezone-aware metadata for admin event resources', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminGetResourceMetaTool::class, [
            'resource_key' => 'events',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.resource.timezone_sensitive', true)
            ->where('data.resource.date_semantics.storage_timezone', 'UTC')
            ->where('data.resource.date_semantics.local_date_filter', 'starts_on_local_date')
            ->where('data.resource.mcp_tools.list_records.arguments.filters', 'object')
            ->where('data.resource.mcp_tools.list_records.arguments.starts_after', null)
            ->where('data.resource.mcp_tools.list_records.arguments.starts_before', null)
            ->where('data.resource.mcp_tools.list_records.arguments.starts_on_local_date', null)
            ->where('data.resource.mcp_tools.get_record.tool', 'admin-get-record')
            ->where('data.resource.mcp_tools.get_record_actions.tool', 'admin-get-record-actions')
            ->where('data.resource.mcp_tools.get_record.arguments.resource_key', 'events')
            ->where('data.resource.mcp_tools.get_record.arguments.record_key', 'record')
            ->etc());
});

it('filters admin event records by structured filters through the MCP server', function () {
    $admin = adminMcpUser('super_admin');

    $draftOnlineEvent = Event::factory()->create([
        'title' => 'Admin MCP Filtered Draft Online Event',
        'status' => 'draft',
        'event_format' => EventFormat::Online,
        'visibility' => EventVisibility::Public,
        'event_type' => [EventType::KuliahCeramah->value],
    ]);

    Event::factory()->create([
        'title' => 'Admin MCP Approved Physical Event',
        'status' => 'approved',
        'event_format' => EventFormat::Physical,
        'visibility' => EventVisibility::Private,
        'event_type' => [EventType::Forum->value],
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'status' => 'draft',
                'event_format' => 'online',
                'visibility' => 'public',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $draftOnlineEvent->getKey())
            ->where('data.0.title', 'Admin MCP Filtered Draft Online Event')
            ->where('meta.resource.key', 'events')
            ->etc());
});

it('filters admin event records by status, boolean, and visibility filters through the MCP server', function () {
    $admin = adminMcpUser('super_admin');

    $approvedPublic = Event::factory()->create([
        'title' => 'Admin MCP Single Filter Approved Public',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);

    $draftPrivate = Event::factory()->create([
        'title' => 'Admin MCP Single Filter Draft Private',
        'status' => 'draft',
        'visibility' => EventVisibility::Private,
        'published_at' => null,
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'status' => 'approved',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $approvedPublic->getKey())
            ->where('data.0.title', 'Admin MCP Single Filter Approved Public')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'status' => 'draft',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $draftPrivate->getKey())
            ->where('data.0.title', 'Admin MCP Single Filter Draft Private')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'published' => true,
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $approvedPublic->getKey())
            ->where('data.0.title', 'Admin MCP Single Filter Approved Public')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'published' => false,
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $draftPrivate->getKey())
            ->where('data.0.title', 'Admin MCP Single Filter Draft Private')
            ->etc());
});

it('surfaces public event change projections on admin event record detail through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $actor = User::factory()->create();
    $original = Event::factory()->create([
        'title' => 'Admin MCP Change Surface Original',
        'slug' => 'admin-mcp-change-surface-original',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);
    $firstReplacement = Event::factory()->create([
        'title' => 'Admin MCP Change Surface First Replacement',
        'slug' => 'admin-mcp-change-surface-first-replacement',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);
    $finalReplacement = Event::factory()->create([
        'title' => 'Admin MCP Change Surface Final Replacement',
        'slug' => 'admin-mcp-change-surface-final-replacement',
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
                'status' => EventChangeStatus::Published->value,
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
                'status' => EventChangeStatus::Published->value,
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
                'status' => EventChangeStatus::Published->value,
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

    AdminServer::actingAs($admin)
        ->tool(AdminGetRecordTool::class, [
            'resource_key' => 'events',
            'record_key' => $original->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.route_key', $original->getRouteKey())
            ->where('data.record.attributes.active_change_notice.type', EventChangeType::Other->value)
            ->where('data.record.attributes.replacement_event.id', $firstReplacement->id)
            ->where('data.record.attributes.change_announcements.1.type', EventChangeType::ReplacementLinked->value)
            ->where('data.record.attributes.change_announcements.1.replacement_event.id', $firstReplacement->id)
            ->missing('data.record.attributes.latest_published_change_announcement')
            ->missing('data.record.attributes.latest_published_replacement_announcement')
            ->missing('data.record.attributes.published_change_announcements')
            ->etc());
});

it('filters admin event records by local date through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $matchingEvent = Event::factory()->create([
        'title' => 'Admin MCP Date Match',
        'starts_at' => Carbon::parse('2026-05-01 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin MCP Date Miss',
        'starts_at' => Carbon::parse('2026-05-02 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_on_local_date' => '2026-05-01',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $matchingEvent->getKey())
            ->where('data.0.title', 'Admin MCP Date Match')
            ->where('data.0.attributes.starts_on_local_date', '2026-05-01')
            ->where('meta.resource.key', 'events')
            ->where('meta.search', null)
            ->etc());
});

it('combines local-date and structured filters through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $matchingEvent = Event::factory()->create([
        'title' => 'Admin MCP Date Plus Filter Match',
        'starts_at' => Carbon::parse('2026-05-06 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin MCP Date Plus Filter Wrong Status',
        'starts_at' => Carbon::parse('2026-05-06 05:00:00', 'UTC'),
        'status' => 'draft',
    ]);

    Event::factory()->create([
        'title' => 'Admin MCP Date Plus Filter Wrong Date',
        'starts_at' => Carbon::parse('2026-05-07 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_on_local_date' => '2026-05-06',
            'filters' => [
                'status' => 'approved',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $matchingEvent->getKey())
            ->where('data.0.title', 'Admin MCP Date Plus Filter Match')
            ->where('data.0.attributes.starts_on_local_date', '2026-05-06')
            ->etc());
});

it('filters admin event records by local date ranges through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $beforeRange = Event::factory()->create([
        'title' => 'Admin MCP Date Range Before',
        'starts_at' => Carbon::parse('2026-05-01 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    $withinRange = Event::factory()->create([
        'title' => 'Admin MCP Date Range Within',
        'starts_at' => Carbon::parse('2026-05-03 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    $afterRange = Event::factory()->create([
        'title' => 'Admin MCP Date Range After',
        'starts_at' => Carbon::parse('2026-05-05 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_after' => '2026-05-03',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 2)
            ->where('data', fn (mixed $records): bool => collect($records)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([
                    (string) $withinRange->getKey(),
                    (string) $afterRange->getKey(),
                ])->sort()->values()->all())
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_before' => '2026-05-03',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 2)
            ->where('data', fn (mixed $records): bool => collect($records)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([
                    (string) $beforeRange->getKey(),
                    (string) $withinRange->getKey(),
                ])->sort()->values()->all())
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_after' => '2026-05-02',
            'starts_before' => '2026-05-04',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $withinRange->getKey())
            ->where('data.0.title', 'Admin MCP Date Range Within')
            ->etc());
});

it('searches admin event records and combines search with local dates through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $kuliahEvent = Event::factory()->create([
        'title' => 'Admin MCP Search Kuliah Match',
        'starts_at' => Carbon::parse('2026-05-08 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    $dhuhaDateMatch = Event::factory()->create([
        'title' => 'Admin MCP Search Dhuha Date Match',
        'starts_at' => Carbon::parse('2026-05-08 03:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'title' => 'Admin MCP Search Dhuha Wrong Date',
        'starts_at' => Carbon::parse('2026-05-09 03:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'search' => 'Kuliah Match',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $kuliahEvent->getKey())
            ->where('data.0.title', 'Admin MCP Search Kuliah Match')
            ->where('meta.search', 'Kuliah Match')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'search' => 'Dhuha',
            'starts_on_local_date' => '2026-05-08',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $dhuhaDateMatch->getKey())
            ->where('data.0.title', 'Admin MCP Search Dhuha Date Match')
            ->where('data.0.attributes.starts_on_local_date', '2026-05-08')
            ->where('meta.search', 'Dhuha')
            ->etc());
});

it('lists admin event records without server errors through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $event = Event::factory()->create([
        'title' => 'Admin MCP Unfiltered Event',
        'starts_at' => Carbon::parse('2026-04-23 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'page' => 1,
            'per_page' => 50,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $event->getKey())
            ->where('data.0.title', 'Admin MCP Unfiltered Event')
            ->where('data.0.attributes.starts_on_local_date', '2026-04-23')
            ->where('meta.resource.key', 'events')
            ->where('meta.pagination.page', 1)
            ->etc());
});

it('lists admin event records with prayer-relative metadata through the MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $admin->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $event = Event::factory()->create([
        'title' => 'Admin MCP Prayer Relative Event',
        'starts_at' => Carbon::parse('2026-04-23 02:00:00', 'UTC'),
        'event_type' => [EventType::KuliahCeramah->value],
        'age_group' => [EventAgeGroup::AllAges->value],
        'timing_mode' => 'prayer_relative',
        'prayer_reference' => PrayerReference::Maghrib->value,
        'prayer_offset' => PrayerOffset::Immediately->value,
        'prayer_display_text' => EventPrayerTime::SelepasMaghrib->getLabel(),
        'status' => 'approved',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'status' => 'approved',
            ],
            'starts_on_local_date' => '2026-04-23',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $event->getKey())
            ->where('data.0.title', 'Admin MCP Prayer Relative Event')
            ->where('data.0.attributes.event_type.0', EventType::KuliahCeramah->value)
            ->where('data.0.attributes.age_group.0', EventAgeGroup::AllAges->value)
            ->where('data.0.attributes.prayer_reference', PrayerReference::Maghrib->value)
            ->where('data.0.attributes.prayer_offset', PrayerOffset::Immediately->value)
            ->where('data.0.attributes.starts_on_local_date', '2026-04-23')
            ->where('data.0.attributes.timing_display', EventPrayerTime::SelepasMaghrib->getLabel())
            ->where('meta.resource.key', 'events')
            ->etc());
});

it('exposes tag write schema and creates and updates tags through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'tags',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'tags')
            ->where('data.schema.resource_key', 'tags')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'tags')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'tags',
            'payload' => [
                'name' => [
                    'ms' => 'Tadabbur MCP',
                    'en' => 'Reflection MCP',
                ],
                'type' => 'discipline',
                'status' => 'verified',
            ],
        ])
        ->assertOk();

    $tagRouteKey = (string) Tag::query()
        ->where('type', 'discipline')
        ->where('name->ms', 'Tadabbur MCP')
        ->value('id');

    expect($tagRouteKey)->not->toBe('');

    expect(Tag::query()->findOrFail($tagRouteKey)->type)->toBe('discipline');

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'tags',
            'record_key' => $tagRouteKey,
            'payload' => [
                'name' => [
                    'ms' => 'Rasuah MCP',
                    'en' => 'Corruption MCP',
                ],
                'type' => 'issue',
                'status' => 'pending',
                'order_column' => 7,
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.type', 'issue')
            ->where('data.record.attributes.status', 'pending')
            ->where('data.record.attributes.order_column', 7)
            ->etc());

    $tag = Tag::query()->findOrFail($tagRouteKey);

    expect($tag->status)->toBe('pending')
        ->and($tag->order_column)->toBe(7)
        ->and($tag->getTranslation('slug', 'en'))->toBe('corruption-mcp');
});

it('moderates events through the admin MCP workflow tool', function () {
    $admin = adminMcpUser('super_admin');
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminModerateEventTool::class, [
            'record_key' => $event->getKey(),
            'action' => 'remoderate',
            'note' => 'Content needs re-review.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.record.route_key', $event->getRouteKey())
            ->where('data.record.attributes.status', 'pending')
            ->etc());

    $event->refresh();

    expect((string) $event->status)->toBe('pending');

    $review = OwnerContext::withOwner(null, fn () => ModerationReview::query()->whereEventId($event->getKey())->latest()->first());

    expect($review?->type?->value)->toBe('remoderated');
});

it('submits draft events for moderation through the admin MCP workflow tool', function () {
    $admin = adminMcpUser('super_admin');
    $event = Event::factory()->create([
        'status' => 'draft',
        'published_at' => null,
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.defaults.action', 'submit_for_moderation')
            ->where('data.schema.fields.0.allowed_values', ['submit_for_moderation'])
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminModerateEventTool::class, [
            'record_key' => $event->getKey(),
            'action' => 'submit_for_moderation',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.record.route_key', $event->getRouteKey())
            ->where('data.record.attributes.status', 'pending')
            ->etc());

    expect((string) ($event->fresh()?->status))->toBe('pending');

    AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.fields.0.allowed_values', ['approve', 'request_changes', 'reject', 'cancel'])
            ->etc());
});

it('reviews contribution requests through the admin MCP workflow tool', function () {
    $admin = adminMcpUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Pending MCP Speaker',
        'status' => 'pending',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Speaker,
        'entity_type' => $speaker->getMorphClass(),
        'entity_id' => $speaker->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => $speaker->name,
            'gender' => 'female',
        ],
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminReviewContributionRequestTool::class, [
            'record_key' => $request->getKey(),
            'action' => 'reject',
            'reason_code' => 'needs_more_evidence',
            'reviewer_note' => 'Need stronger sourcing.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'contribution-requests')
            ->where('data.record.route_key', $request->getRouteKey())
            ->where('data.record.attributes.status', 'rejected')
            ->where('data.record.attributes.reason_code', 'needs_more_evidence')
            ->etc());

    expect($request->fresh()?->status)->toBe(ContributionRequestStatus::Rejected)
        ->and($request->fresh()?->reason_code)->toBe('needs_more_evidence')
        ->and($speaker->fresh()?->status)->toBe('rejected')
        ->and((string) $speaker->fresh()?->status)->toBeIn(['inactive', 'rejected']);
});

it('returns explicit admin workflow schemas through dedicated MCP schema tools', function () {
    $admin = adminMcpUser('super_admin');
    $event = Event::factory()->create([
        'status' => 'pending',
    ]);
    $report = Report::factory()->create([
        'status' => 'open',
    ]);
    $speaker = Speaker::factory()->create([
        'name' => 'Schema MCP Speaker',
        'status' => 'pending',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Speaker,
        'entity_type' => $speaker->getMorphClass(),
        'entity_id' => $speaker->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => $speaker->name,
            'gender' => 'female',
        ],
    ]);
    $institution = Institution::factory()->create();
    $applicant = User::factory()->create();
    $application = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $applicant->getKey(),
            'status' => 'pending',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.record.route_key', $event->getRouteKey())
            ->where('data.schema.action', 'moderate_event')
            ->where('data.schema.defaults.action', 'approve')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetReportTriageSchemaTool::class, [
            'record_key' => $report->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'reports')
            ->where('data.record.route_key', $report->getRouteKey())
            ->where('data.schema.action', 'triage_report')
            ->where('data.schema.defaults.action', 'triage')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetContributionRequestReviewSchemaTool::class, [
            'record_key' => $request->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'contribution-requests')
            ->where('data.record.route_key', $request->getRouteKey())
            ->where('data.schema.action', 'review_contribution_request')
            ->where('data.schema.defaults.action', 'approve')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetMembershipApplicationReviewSchemaTool::class, [
            'record_key' => $application->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'membership-applications')
            ->where('data.record.route_key', $application->getRouteKey())
            ->where('data.schema.action', 'review_membership_application')
            ->where('data.schema.defaults.action', 'approve')
            ->etc());
});

it('exposes series write schema and creates and updates series through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $suffix = Str::lower((string) Str::ulid());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'series',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'series')
            ->where('data.schema.resource_key', 'series')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'series')
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'cover'), 'mcp_upload.shape') === 'file_descriptor'
                && data_get(collect($fields)->firstWhere('name', 'gallery'), 'mcp_upload.shape') === 'array<file_descriptor>')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'series',
            'payload' => [
                'title' => 'Admin MCP Series '.$suffix,
                'slug' => 'admin-mcp-series-'.$suffix,
                'description' => 'Series created through MCP.',
                'visibility' => 'public',
                'status' => 'active',
                'cover' => adminMcpImageDescriptor('series-cover.png'),
                'gallery' => [
                    adminMcpImageDescriptor('series-gallery.png'),
                ],
            ],
        ])
        ->assertOk();

    $seriesRouteKey = (string) Series::query()->where('slug', 'admin-mcp-series-'.$suffix)->value('id');

    expect($seriesRouteKey)->not->toBe('');

    $series = Series::query()->findOrFail($seriesRouteKey);

    expect($series->getMedia('cover'))->toHaveCount(1)
        ->and($series->getMedia('gallery'))->toHaveCount(1);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'series',
            'record_key' => $seriesRouteKey,
            'payload' => [
                'title' => 'Admin MCP Series Updated '.$suffix,
                'slug' => 'admin-mcp-series-updated-'.$suffix,
                'description' => 'Series updated through MCP.',
                'visibility' => 'private',
                'status' => 'inactive',
                'languages' => [],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.title', 'Admin MCP Series Updated '.$suffix)
            ->where('data.record.attributes.slug', 'admin-mcp-series-updated-'.$suffix)
            ->where('data.record.attributes.visibility', 'private')
            ->where('data.record.attributes.status', 'inactive')
            ->etc());

    $series->refresh();

    expect($series->title)->toBe('Admin MCP Series Updated '.$suffix)
        ->and($series->slug)->toBe('admin-mcp-series-updated-'.$suffix)
        ->and($series->visibility)->toBe('private')
        ->and((string) $series->status)->toBe('inactive');
});

it('exposes space write schema and creates and updates spaces through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $suffix = Str::lower((string) Str::ulid());
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'spaces',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'spaces')
            ->where('data.schema.resource_key', 'spaces')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'spaces')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'spaces',
            'payload' => [
                'name' => 'Admin MCP Space '.$suffix,
                'slug' => 'admin-mcp-space-'.$suffix,
                'capacity' => 40,
                'status' => 'active',
                'institutions' => [(string) $firstInstitution->getKey()],
            ],
        ])
        ->assertOk();

    $spaceRouteKey = (string) Space::query()->where('slug', 'admin-mcp-space-'.$suffix)->value('id');

    expect($spaceRouteKey)->not->toBe('');

    $space = Space::query()->findOrFail($spaceRouteKey);

    expect($space->institutions()->pluck('institutions.id')->all())->toContain($firstInstitution->getKey());

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'spaces',
            'record_key' => $spaceRouteKey,
            'payload' => [
                'name' => 'Admin MCP Space Updated '.$suffix,
                'slug' => 'admin-mcp-space-updated-'.$suffix,
                'capacity' => 65,
                'status' => 'inactive',
                'institutions' => [(string) $secondInstitution->getKey()],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.name', 'Admin MCP Space Updated '.$suffix)
            ->where('data.record.attributes.slug', 'admin-mcp-space-updated-'.$suffix)
            ->where('data.record.attributes.capacity', 65)
            ->where('data.record.attributes.status', 'inactive')
            ->etc());

    $space->refresh();

    expect($space->name)->toBe('Admin MCP Space Updated '.$suffix)
        ->and($space->slug)->toBe('admin-mcp-space-updated-'.$suffix)
        ->and($space->capacity)->toBe(65)
        ->and((string) $space->status)->toBe('inactive')
        ->and($space->institutions()->pluck('institutions.id')->all())->toContain($secondInstitution->getKey())
        ->and($space->institutions()->pluck('institutions.id')->all())->not->toContain($firstInstitution->getKey());
});

it('exposes donation channel write schema and creates and updates donation channels through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'donation-channels',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'donation-channels')
            ->where('data.schema.resource_key', 'donation-channels')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'donation-channels')
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'qr'), 'mcp_upload.shape') === 'file_descriptor'
                && collect($fields)
                    ->pluck('name')
                    ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'clear_'))
                    ->isEmpty())
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'donation-channels',
            'payload' => [
                'donatable_type' => 'institution',
                'donatable_id' => (string) $institution->getKey(),
                'label' => 'Tabung MCP',
                'recipient' => 'Masjid MCP',
                'method' => 'bank_account',
                'bank_code' => 'CIMB',
                'bank_name' => 'CIMB',
                'account_number' => '9876543210',
                'status' => 'verified',
                'is_default' => true,
                'qr' => adminMcpImageDescriptor('admin-mcp-donation-channel-qr.png'),
            ],
        ])
        ->assertOk();

    $donationChannelRouteKey = (string) DonationChannel::query()
        ->where('recipient', 'Masjid MCP')
        ->value('id');

    expect($donationChannelRouteKey)->not->toBe('');

    $donationChannel = DonationChannel::query()->findOrFail($donationChannelRouteKey);

    expect($donationChannel->donatable_type)->toBe('institution')
        ->and($donationChannel->donatable_id)->toBe($institution->getKey())
        ->and($donationChannel->method)->toBe('bank_account')
        ->and($donationChannel->bank_name)->toBe('CIMB')
        ->and($donationChannel->duitnow_type)->toBeNull()
        ->and($donationChannel->getMedia('qr'))->toHaveCount(1);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'donation-channels',
            'record_key' => $donationChannelRouteKey,
            'payload' => [
                'donatable_type' => 'institution',
                'donatable_id' => (string) $institution->getKey(),
                'label' => 'Tabung MCP Updated',
                'recipient' => 'Masjid MCP Updated',
                'method' => 'duitnow',
                'duitnow_type' => 'mobile',
                'duitnow_value' => '60123456789',
                'status' => 'inactive',
                'is_default' => false,
                'qr' => adminMcpImageDescriptor('admin-mcp-donation-channel-qr-updated.png'),
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.label', 'Tabung MCP Updated')
            ->where('data.record.attributes.method', 'duitnow')
            ->where('data.record.attributes.status', 'inactive')
            ->etc());

    $donationChannel->refresh();

    expect($donationChannel->label)->toBe('Tabung MCP Updated')
        ->and($donationChannel->recipient)->toBe('Masjid MCP Updated')
        ->and($donationChannel->method)->toBe('duitnow')
        ->and($donationChannel->bank_code)->toBeNull()
        ->and($donationChannel->bank_name)->toBeNull()
        ->and($donationChannel->account_number)->toBeNull()
        ->and($donationChannel->duitnow_type)->toBe('mobile')
        ->and($donationChannel->duitnow_value)->toBe('60123456789')
        ->and($donationChannel->status)->toBe('inactive')
        ->and($donationChannel->getMedia('qr'))->toHaveCount(1);
});

it('exposes inspiration write schema and creates and updates inspirations through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'inspirations',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'inspirations')
            ->where('data.schema.resource_key', 'inspirations')
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.tool_arguments.resource_key', 'inspirations')
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'main'), 'mcp_upload.shape') === 'file_descriptor')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'inspirations',
            'payload' => [
                'category' => 'quran_quote',
                'locale' => 'ms',
                'title' => 'Admin MCP Inspiration',
                'content' => 'Inspiration created through MCP.',
                'source' => 'MCP Source',
                'status' => 'active',
                'main' => adminMcpImageDescriptor('admin-mcp-inspiration-main.png'),
            ],
        ])
        ->assertOk();

    $inspirationRouteKey = (string) Inspiration::query()->where('title', 'Admin MCP Inspiration')->value('id');

    expect($inspirationRouteKey)->not->toBe('');

    $inspiration = Inspiration::query()->findOrFail($inspirationRouteKey);

    expect($inspiration->getRawOriginal('category'))->toBe('quran_quote')
        ->and($inspiration->locale)->toBe('ms')
        ->and($inspiration->getMedia('main'))->toHaveCount(1);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'inspirations',
            'record_key' => $inspirationRouteKey,
            'payload' => [
                'category' => 'hadith_quote',
                'locale' => 'en',
                'title' => 'Admin MCP Inspiration Updated',
                'content' => 'Inspiration updated through MCP.',
                'source' => 'Updated MCP Source',
                'status' => 'inactive',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.category', 'hadith_quote')
            ->where('data.record.attributes.locale', 'en')
            ->where('data.record.attributes.title', 'Admin MCP Inspiration Updated')
            ->where('data.record.attributes.status', 'inactive')
            ->etc());

    $inspiration->refresh();

    expect($inspiration->getRawOriginal('category'))->toBe('hadith_quote')
        ->and($inspiration->locale)->toBe('en')
        ->and($inspiration->title)->toBe('Admin MCP Inspiration Updated')
        ->and($inspiration->source)->toBe('Updated MCP Source')
        ->and((string) $inspiration->status)->toBe('inactive');
});

it('surfaces space report and inspiration update semantics through admin MCP write schemas', function () {
    $admin = adminMcpUser('super_admin');
    $space = Space::factory()->create([
        'capacity' => 40,
    ]);
    $report = Report::factory()->create([
        'status' => 'open',
    ]);
    $inspiration = Inspiration::factory()->create([
        'source' => 'Schema Source',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'spaces',
            'operation' => 'update',
            'record_key' => (string) $space->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'spaces')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('slug'), 'uniqueness_scope') === 'spaces.slug'
                    && data_get($fieldMap->get('capacity'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('institutions'), 'collection_semantics.submitted_array') === 'replace_relation_sync';
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'reports',
            'operation' => 'update',
            'record_key' => (string) $report->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'reports')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('entity_type'), 'paired_with') === 'entity_id'
                    && data_get($fieldMap->get('category'), 'allowed_values_resolved_from') === 'entity_type'
                    && data_get($fieldMap->get('reporter_id'), 'relation') === 'users'
                    && data_get($fieldMap->get('resolution_note'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('evidence'), 'collection_semantics.explicit_null') === 'preserve_existing_collection'
                    && data_get($fieldMap->get('evidence'), 'raw_http_clear_flag') === 'clear_evidence';
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'inspirations',
            'operation' => 'update',
            'record_key' => (string) $inspiration->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'inspirations')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('content'), 'input_normalization.kind') === 'rich_text_document'
                    && data_get($fieldMap->get('source'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('main'), 'mutation_semantics') === 'replace_single_media_collection'
                    && data_get($fieldMap->get('main'), 'raw_http_clear_flag') === 'clear_main';
            })
            ->etc());
});

it('surfaces tag and address-area update semantics through admin MCP write schemas', function () {
    $admin = adminMcpUser('super_admin');
    $tag = Tag::factory()->discipline()->verified()->create();

    $countryId = ensureMcpMalaysiaCountryExists();
    $state = createTestAddressArea('Admin MCP Negeri '.Str::lower(Str::random(8)), 1, country: ensureTestMalaysiaCountry(), type: 'state');
    $district = createTestAddressArea('Admin MCP Daerah '.Str::lower(Str::random(8)), 2, parent: $state, country: ensureTestMalaysiaCountry(), type: 'district');
    $subdistrict = createTestAddressArea('Admin MCP Subdistrict '.Str::lower(Str::random(8)), 3, parent: $district, country: ensureTestMalaysiaCountry(), type: 'subdistrict');

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'tags',
            'operation' => 'update',
            'record_key' => (string) $tag->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'tags')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('name'), 'translation_fallback.en') === 'name.ms'
                    && data_get($fieldMap->get('name.en'), 'clear_semantics.explicit_null') === 'fallback_to_name.ms'
                    && data_get($fieldMap->get('order_column'), 'clear_semantics.explicit_null') === 'recompute_with_sortable_scope';
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'address-areas',
            'operation' => 'update',
            'record_key' => (string) $subdistrict->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'address-areas')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('country_id'), 'required') === false
                    && data_get($fieldMap->get('parent_id'), 'required') === false
                    && data_get($fieldMap->get('level'), 'input_type') === 'integer'
                    && data_get($fieldMap->get('type'), 'required') === false
                    && data_get($fieldMap->get('name'), 'normalization.trim') === true;
            })
            ->etc());
});

it('returns write schema for supported resources and rejects unknown resources', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'speakers',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'speakers')
            ->where('data.schema.method', 'POST')
            ->where('data.schema.transport', 'mcp')
            ->where('data.schema.tool', 'admin-create-record')
            ->where('data.schema.tool_arguments.resource_key', 'speakers')
            ->where('data.schema.tool_arguments.payload', 'object')
            ->where('data.schema.tool_arguments.validate_only', false)
            ->where('data.schema.tool_arguments.apply_defaults', false)
            ->where('data.schema.apply_defaults_semantics.scope', 'preview_only')
            ->where('data.schema.apply_defaults_semantics.honored_when', 'validate_only=true and apply_defaults=true')
            ->where('data.schema.apply_defaults_semantics.persisted_writes', 'ignored; send the values you want saved')
            ->where('data.schema.endpoint', null)
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.media_uploads_supported', true)
            ->where('data.schema.media_upload_transport', 'json_base64_descriptor_or_download_url')
            ->where('data.schema.unsupported_fields', [])
            ->where('data.schema.fields', fn ($fields): bool => collect($fields)
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'clear_'))
                ->isEmpty())
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'avatar'), 'mcp_upload.shape') === 'file_descriptor'
                && data_get(collect($fields)->firstWhere('name', 'gallery'), 'mcp_upload.shape') === 'array<file_descriptor>'
                && data_get(collect($fields)->firstWhere('name', 'gallery'), 'mcp_upload.accepted_mime_types.0') === 'image/jpeg')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'events',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'events')
            ->where('data.schema.method', 'POST')
            ->where('data.schema.tool', 'admin-create-event')
            ->where('data.schema.tool_arguments.primary_organizer_key', 'route_key')
            ->where('data.schema.tool_arguments.institution_key', 'route_key')
            ->where('data.schema.mcp_only_semantics.route_key_aliases.speaker_keys', 'resolves to speakers')
            ->where('data.schema.mcp_only_semantics.update_relation_arrays.empty_array', 'detach all related records for that alias')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'references',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'references')
            ->where('data.schema.method', 'POST')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'address-areas',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'address-areas')
            ->where('data.schema.method', 'POST')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'not-a-resource',
            'operation' => 'create',
        ])
        ->assertHasErrors(['Resource not found.']);
});

it('previews admin speaker creation through the MCP write tool without persisting the record', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'speakers',
            'validate_only' => true,
            'payload' => [
                'name' => 'Previewed Admin MCP Speaker',
                'gender' => 'male',
                'is_freelance' => false,
                'status' => 'active',
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'speakers')
            ->where('data.preview.validate_only', true)
            ->where('data.preview.operation', 'create')
            ->where('data.preview.normalized_payload.address.country_id', $countryId)
            ->where('data.preview.current_record', null)
            ->etc());

    expect(Speaker::query()->where('name', 'Previewed Admin MCP Speaker')->exists())->toBeFalse();
});

it('previews admin speaker updates through the MCP write tool without persisting the record', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Previewable Admin MCP Speaker',
        'is_freelance' => false,
        'job_title' => null,
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speaker->getKey(),
            'validate_only' => true,
            'payload' => [
                'name' => 'Previewed Admin MCP Speaker Updated',
                'gender' => 'male',
                'is_freelance' => true,
                'job_title' => 'Imam',
                'status' => 'active',
                'allow_public_event_submission' => true,
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'speakers')
            ->where('data.preview.validate_only', true)
            ->where('data.preview.operation', 'update')
            ->where('data.preview.current_record.route_key', $speaker->getRouteKey())
            ->where('data.preview.normalized_payload.job_title', 'Imam')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speaker->getKey(),
            'payload' => [
                'name' => 'Previewed Admin MCP Speaker Updated',
                'gender' => 'male',
                'is_freelance' => true,
                'job_title' => 'Imam',
                'status' => 'active',
                'allow_public_event_submission' => true,
                'address' => [
                    'country_id' => $countryId,
                ],
                'clear_cover' => true,
            ],
        ])
        ->assertHasErrors(['Destructive media clear flags are not supported through MCP. Upload a replacement file or array when the schema advertises that media field.']);

    expect(Speaker::query()->findOrFail($speaker->getKey())->name)->toBe('Previewable Admin MCP Speaker')
        ->and(Speaker::query()->findOrFail($speaker->getKey())->job_title)->toBeNull();
});

it('returns remediation details for validate-only admin create validation failures', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'speakers',
            'validate_only' => true,
            'payload' => [
                'name' => 'Remediation Preview Speaker',
            ],
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.fix_plan', function ($fixPlan): bool {
                $keyedFixPlan = collect($fixPlan)->keyBy('field');

                return $keyedFixPlan->get('gender') === [
                    'action' => 'set_field',
                    'field' => 'gender',
                    'value' => 'male',
                    'auto_apply_safe' => true,
                ] && $keyedFixPlan->get('status') === [
                    'action' => 'choose_one',
                    'field' => 'status',
                    'options' => ['pending', 'verified', 'rejected'],
                    'auto_apply_safe' => false,
                ];
            })
            ->where('error.details.normalized_payload_preview.name', 'Remediation Preview Speaker')
            ->where('error.details.normalized_payload_preview.gender', 'male')
            ->where('error.details.remaining_blockers', function ($remainingBlockers): bool {
                $blockers = collect($remainingBlockers)->keyBy('field');
                $statusBlocker = $blockers->get('status');

                return is_array($statusBlocker)
                    && ($statusBlocker['field'] ?? null) === 'status'
                    && ($statusBlocker['type'] ?? null) === 'required_choice'
                    && ($statusBlocker['options'] ?? null) === ['pending', 'verified', 'rejected']
                    && $blockers->has('address')
                    && $blockers->has('address.country_id');
            })
            ->where('error.details.can_retry', false)
            ->etc());
});

it('returns retryable remediation details for validate-only admin update validation failures', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Retryable Admin MCP Speaker',
        'gender' => 'male',
        'status' => 'verified',
    ]);
    $originalGender = $speaker->gender;
    $originalStatus = $speaker->status;

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speaker->getKey(),
            'validate_only' => true,
            'payload' => [
                'name' => 'Retryable Admin MCP Speaker Updated',
            ],
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.fix_plan', function ($fixPlan) use ($originalGender, $originalStatus): bool {
                $keyedFixPlan = collect($fixPlan)->keyBy('field');

                return $keyedFixPlan->get('gender') === [
                    'action' => 'set_field',
                    'field' => 'gender',
                    'value' => $originalGender,
                    'auto_apply_safe' => true,
                ] && $keyedFixPlan->get('status') === [
                    'action' => 'set_field',
                    'field' => 'status',
                    'value' => $originalStatus,
                    'auto_apply_safe' => true,
                ];
            })
            ->where('error.details.normalized_payload_preview.name', 'Retryable Admin MCP Speaker Updated')
            ->where('error.details.normalized_payload_preview.gender', $originalGender)
            ->where('error.details.normalized_payload_preview.status', $originalStatus)
            ->has('error.details.remaining_blockers', 0)
            ->where('error.details.can_retry', true)
            ->etc());
});

it('registers admin write tools when the MCP actor is a normalized Passport user', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs(adminPassportUser($admin))
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'speakers',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertHasNoErrors();
});

it('creates and updates speakers through MCP write tools', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'speakers',
            'payload' => [
                'name' => 'Admin MCP Created Speaker',
                'gender' => 'male',
                'is_freelance' => false,
                'status' => 'active',
                'avatar' => adminMcpImageDescriptor('admin-mcp-avatar'),
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk();

    $speaker = Speaker::query()->where('name', 'Admin MCP Created Speaker')->firstOrFail();
    $speakerId = (string) $speaker->getKey();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'speakers',
            'operation' => 'update',
            'record_key' => $speakerId,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'speakers')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');
                $qualificationItemFields = collect(data_get($fieldMap->get('qualifications'), 'item_schema.fields', []))->keyBy('name');

                return data_get($fieldMap->get('address'), 'required') === false
                    && data_get($fieldMap->get('address'), 'clear_semantics.empty_object') === 'invalid_without_country'
                    && data_get($fieldMap->get('address.country_id'), 'required_when_parent_present_on_update') === true
                    && data_get($fieldMap->get('language_ids'), 'collection_semantics.submitted_array') === 'replace_relation_sync'
                    && data_get($fieldMap->get('contactMethods'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false
                    && $qualificationItemFields->has('institution')
                    && $qualificationItemFields->has('degree');
            })
            ->etc());

    expect($speaker->name)->toBe('Admin MCP Created Speaker')
        ->and($speaker->status)->toBe('verified')
        ->and($speaker->allow_public_event_submission)->toBeTrue()
        ->and($speaker->getMedia('avatar'))->toHaveCount(1)
        ->and($speaker->getFirstMedia('avatar')?->file_name)->toEndWith('.png');

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => $speakerId,
            'payload' => [
                'name' => 'Admin MCP Updated Speaker',
                'gender' => 'male',
                'is_freelance' => true,
                'job_title' => 'Imam',
                'status' => 'active',
                'allow_public_event_submission' => true,
                'gallery' => [
                    adminMcpImageDescriptor('admin-mcp-gallery'),
                ],
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.name', 'Admin MCP Updated Speaker')
            ->where('data.record.attributes.job_title', 'Imam')
            ->etc());

    expect($speaker->fresh()?->getMedia('gallery'))->toHaveCount(1);
});

it('requires an explicit speaker country when the address is mutated through admin MCP write tools', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Admin MCP Speaker Address Guard',
        'gender' => 'male',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => (string) $speaker->getKey(),
            'payload' => [
                'name' => 'Admin MCP Speaker Address Guard',
                'gender' => 'male',
                'status' => 'verified',
                'address' => [],
            ],
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.errors', fn ($errors): bool => (collect($errors)->get('address.country_id')[0] ?? null) === 'The address country is required.')
            ->etc());
});

it('creates and updates institutions through MCP write tools', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'institutions',
            'payload' => [
                'name' => 'Admin MCP Institution',
                'nickname' => 'MCP Surau',
                'type' => 'masjid',
                'status' => 'verified',
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk();

    $institution = Institution::query()->where('name', 'Admin MCP Institution')->firstOrFail();
    $institutionId = (string) $institution->getKey();
    $originalAddress = $institution->fresh()?->primaryAddress();
    $originalLat = $originalAddress?->lat;
    $originalLng = $originalAddress?->lng;

    expect($institution->display_name)->toBe('Admin MCP Institution (MCP Surau)')
        ->and($institution->status)->toBe('verified')
        ->and($institution->allow_public_event_submission)->toBeTrue();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'institutions',
            'operation' => 'update',
            'record_key' => $institutionId,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'institutions')
            ->where('data.schema.operation', 'update')
            ->where('data.schema.transport', 'mcp')
            ->where('data.schema.tool', 'admin-update-record')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');
                $contactItemFields = collect(data_get($fieldMap->get('contactMethods'), 'item_schema.fields', []))->keyBy('name');

                return data_get($fieldMap->get('address'), 'required') === false
                    && data_get($fieldMap->get('address.country_id'), 'required') === false
                    && data_get($fieldMap->get('nickname'), 'clear_semantics.explicit_null') === 'preserve_existing'
                    && data_get($fieldMap->get('contactMethods'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && $contactItemFields->has('type')
                    && $contactItemFields->has('value')
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false;
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institutionId,
            'payload' => [
                'name' => 'Admin MCP Institution Updated',
                'nickname' => 'MCP Masjid',
                'type' => 'masjid',
                'status' => 'pending',
                'allow_public_event_submission' => true,
                'slug' => 'attempted-admin-institution-injection',
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.name', 'Admin MCP Institution Updated')
            ->where('data.record.attributes.nickname', 'MCP Masjid')
            ->etc());

    expect($institution->fresh()?->slug)->not->toBe('attempted-admin-institution-injection')
        ->and(abs(((float) $institution->fresh()?->primaryAddress()?->lat) - (float) $originalLat))->toBeLessThan(0.000001)
        ->and(abs(((float) $institution->fresh()?->primaryAddress()?->lng) - (float) $originalLng))->toBeLessThan(0.000001);
});

it('preserves institution nickname on null and clears it on empty string through admin MCP write tools', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'name' => 'Admin MCP Institution Nickname',
        'nickname' => 'MCP Surau',
        'type' => 'masjid',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
            'payload' => [
                'name' => 'Admin MCP Institution Nickname',
                'nickname' => null,
                'type' => 'masjid',
                'status' => 'verified',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.nickname', 'MCP Surau')
            ->etc());

    expect($institution->fresh()?->nickname)->toBe('MCP Surau');

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
            'payload' => [
                'name' => 'Admin MCP Institution Nickname',
                'nickname' => '',
                'type' => 'masjid',
                'status' => 'verified',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.nickname', null)
            ->etc());

    expect($institution->fresh()?->nickname)->toBeNull();
});

it('surfaces venue and reference update semantics through admin MCP write schemas', function () {
    $admin = adminMcpUser('super_admin');
    $venue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'venues',
            'operation' => 'update',
            'record_key' => (string) $venue->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'venues')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('name'), 'required') === false
                    && data_get($fieldMap->get('address'), 'required') === false
                    && data_get($fieldMap->get('address'), 'clear_semantics.empty_object') === 'delete_existing_address'
                    && data_get($fieldMap->get('facilities'), 'input_normalization.kind') === 'facility_list_to_boolean_map'
                    && data_get($fieldMap->get('contactMethods'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false;
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'references',
            'operation' => 'update',
            'record_key' => (string) $reference->getRouteKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'references')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('author'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('publication_year'), 'normalization.empty_string_at_mutation_layer') === 'null'
                    && data_get($fieldMap->get('social_media'), 'collection_semantics.submitted_array') === 'replace_collection'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false;
            })
            ->etc());
});

it('surfaces event series and donation channel update semantics through admin MCP write schemas', function () {
    $admin = adminMcpUser('super_admin');
    $event = Event::factory()->create([
        'status' => 'approved',
    ]);
    $series = Series::factory()->create([
        'description' => 'Series schema surface',
    ]);
    $institution = Institution::factory()->create();
    $donationChannel = DonationChannel::factory()->create([
        'donatable_type' => (string) (new Institution)->getMorphClass(),
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Schema Donation Channel',
        'method' => 'bank_account',
        'bank_name' => 'CIMB',
        'account_number' => '1234567890',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'events',
            'operation' => 'update',
            'record_key' => (string) $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'events')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');
                $otherKeyPeopleFields = collect(data_get($fieldMap->get('other_key_people'), 'item_schema.fields', []))->keyBy('name');

                return data_get($fieldMap->get('title'), 'required') === false
                    && data_get($fieldMap->get('references'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && data_get($fieldMap->get('speakers'), 'collection_semantics.submitted_array') === 'replace_speaker_subset_and_rebuild_key_people'
                    && data_get($fieldMap->get('primary_organizer_id'), 'accepted_models') === [Institution::class, Speaker::class]
                    && data_get($fieldMap->get('registration_mode'), 'lock_behavior.when_event_has_registrations') === 'retain_current_value'
                    && $otherKeyPeopleFields->has('role')
                    && $otherKeyPeopleFields->has('name');
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'series',
            'operation' => 'update',
            'record_key' => (string) $series->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'series')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('slug'), 'uniqueness_scope') === 'series.slug'
                    && data_get($fieldMap->get('description'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('languages'), 'collection_semantics.submitted_array') === 'replace_relation_sync';
            })
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'donation-channels',
            'operation' => 'update',
            'record_key' => (string) $donationChannel->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.resource_key', 'donation-channels')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('donatable_type'), 'accepted_aliases.events') === (string) (new Event)->getMorphClass()
                    && data_get($fieldMap->get('method'), 'switch_clears_fields.ewallet') === ['bank_code', 'bank_name', 'account_number', 'duitnow_type', 'duitnow_value']
                    && data_get($fieldMap->get('label'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('reference_note'), 'normalization.empty_string_at_mutation_layer') === 'null';
            })
            ->etc());
});

it('creates and updates events through MCP write tools', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'slug' => 'masjid-tengku-ampuan-jemaah-bukit-jelutong-petaling-selangor-my',
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminMcpEventTerm('domain', 'Admin MCP Domain');
    $disciplineTag = adminMcpEventTerm('discipline', 'Admin MCP Discipline');
    $sourceTag = adminMcpEventTerm('source', 'Admin MCP Source');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'payload' => adminMcpEventPayload([
                'institution' => $institution,
                'speaker' => $speaker,
                'reference' => $reference,
                'series' => $series,
                'domain_tag' => $domainTag,
                'discipline_tag' => $disciplineTag,
            ]),
        ])
        ->assertOk();

    $event = Event::query()->where('title', 'Admin MCP Event Created')->with(['references', 'series', 'classifications', 'keyPeople'])->firstOrFail();
    $eventId = (string) $event->getKey();
    $oldPath = route('events.show', $event, false);

    $trackedProperty = app(SignalsTracker::class)->defaultTrackedProperty();

    expect($trackedProperty)->not->toBeNull();

    SignalEvent::query()
        ->withoutOwnerScope()
        ->create([
            'tracked_property_id' => (string) $trackedProperty?->getKey(),
            'occurred_at' => now(),
            'event_name' => (string) config('signals.defaults.page_view_event_name', 'page_view'),
            'event_category' => 'page_view',
            'path' => $oldPath,
            'url' => url($oldPath),
            'currency' => 'MYR',
            'revenue_minor' => 0,
        ]);

    expect($event->live_url)->toBeNull()
        ->and($event->accessPolicy?->registration_required)->toBeTrue()
        ->and($event->references->pluck('id')->all())->toContain($reference->getKey())
        ->and($event->series->pluck('id')->all())->toContain($series->getKey())
        ->and($event->classifications->pluck('event_term_id')->all())->toContain($domainTag->getKey(), $disciplineTag->getKey())
        ->and($event->keyPeople)->toHaveCount(2);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'events',
            'record_key' => $eventId,
            'payload' => adminMcpEventPayload([
                'institution' => $institution,
                'speaker' => $speaker,
                'reference' => $reference,
                'series' => $series,
                'domain_tag' => $domainTag,
                'discipline_tag' => $disciplineTag,
            ], [
                'title' => 'Admin MCP Event Updated',
                'event_date' => '2026-06-10',
                'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
                'custom_time' => null,
                'live_url' => 'https://youtube.com/watch?v=admin-mcp-event-live',
                'primary_organizer_id' => $speaker->getKey(),
                'institution_id' => null,
                'references' => [],
                'series' => [],
                'domain_tags' => [],
                'discipline_tags' => [],
                'source_tags' => [(string) $sourceTag->getKey()],
                'speakers' => [],
                'other_key_people' => [],
                'registration_required' => false,
            ]),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.title', 'Admin MCP Event Updated')
            ->where('data.record.attributes.live_url', 'https://youtube.com/watch?v=admin-mcp-event-live')
            ->etc());

    $event->refresh()->load(['references', 'series', 'classifications', 'keyPeople']);

    expect($event->title)->toBe('Admin MCP Event Updated')
        ->and($event->live_url)->toBe('https://youtube.com/watch?v=admin-mcp-event-live')
        ->and($event->accessPolicy?->registration_required)->toBeFalse()
        ->and($event->references)->toHaveCount(0)
        ->and($event->series)->toHaveCount(0)
        ->and($event->classifications->pluck('event_term_id')->all())->toContain($sourceTag->getKey())
        ->and($event->classifications->pluck('event_term_id')->all())->not->toContain($domainTag->getKey(), $disciplineTag->getKey())
        ->and($event->keyPeople)->toHaveCount(0);

    $this->get($oldPath)
        ->assertRedirect(route('events.show', $event));
});

it('emulates production yasin create flow with validate-only then actual create', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $arguments = [
        'title' => 'Majlis Bacaan Yasin',
        'description' => '<p></p>',
        'event_date' => '2026-05-07',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'is_muslim_only' => false,
        'event_type' => [EventType::BacaanYasin->value],
        'primary_organizer_key' => (string) $institution->slug,
        'institution_key' => (string) $institution->slug,
        'registration_required' => false,
        'status' => 'pending',
        'is_featured' => false,
    ];

    AdminServer::actingAs($admin)
        ->tool(AdminCreateEventTool::class, [
            'validate_only' => true,
            'apply_defaults' => false,
            ...$arguments,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.preview.validate_only', true)
            ->where('data.preview.operation', 'create')
            ->where('data.preview.warnings', [])
            ->where('data.preview.normalized_payload.title', 'Majlis Bacaan Yasin')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateEventTool::class, [
            'validate_only' => false,
            'apply_defaults' => false,
            ...$arguments,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.title', 'Majlis Bacaan Yasin')
            ->where('data.record.attributes.starts_on_local_date', '2026-05-07')
            ->where('data.record.attributes.prayer_display_text', 'Selepas Maghrib')
            ->where('data.record.attributes.status', 'pending')
            ->etc());

    $createdEvent = Event::query()
        ->where('title', 'Majlis Bacaan Yasin')
        ->firstOrFail();

    AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => (string) $createdEvent->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.defaults.action', 'approve')
            ->where('data.schema.fields.0.allowed_values', ['approve', 'request_changes', 'reject', 'cancel'])
            ->etc());

    expect(
        (string) ($createdEvent->fresh()?->status)
    )->toBe('pending');
});

it('creates a tazkirah event with speaker_keys via admin-create-event', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminCreateEventTool::class, [
            'title' => 'Tazkirah Test With Speaker',
            'description' => '<p>Test tazkirah</p>',
            'event_date' => '2026-06-15',
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'children_allowed' => false,
            'is_muslim_only' => true,
            'event_type' => [EventType::Tazkirah->value],
            'primary_organizer_key' => (string) $institution->slug,
            'institution_key' => (string) $institution->slug,
            'speaker_keys' => [(string) $speaker->slug],
            'reference_keys' => [(string) $reference->slug],
            'languages' => [101],
            'registration_required' => false,
            'status' => 'pending',
            'is_featured' => false,
            'validate_only' => false,
            'apply_defaults' => false,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.title', 'Tazkirah Test With Speaker')
            ->where('data.record.attributes.status', 'pending')
            ->etc());

    $created = Event::query()->where('title', 'Tazkirah Test With Speaker')->firstOrFail();
    $created->load(['keyPeople', 'references']);

    expect($created->keyPeople)->toHaveCount(1)
        ->and((string) $created->keyPeople->first()?->speaker_id)->toBe((string) $speaker->getKey())
        ->and($created->references)->toHaveCount(1)
        ->and((string) $created->references->first()?->getKey())->toBe((string) $reference->getKey());
});

it('allows admin event create payload to control workflow-ready status', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $basePayload = [
        'description' => '<p>Workflow status payload test</p>',
        'event_date' => '2026-05-08',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'is_muslim_only' => false,
        'event_type' => [EventType::BacaanYasin->value],
        'primary_organizer_id' => (string) $institution->getKey(),
        'institution_id' => (string) $institution->getKey(),
        'registration_required' => false,
        'is_featured' => false,
        'status' => 'draft',
    ];

    AdminServer::actingAs($admin)
        ->tool(AdminGetWriteSchemaTool::class, [
            'resource_key' => 'events',
            'operation' => 'create',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'status'), 'allowed_values') === ['draft', 'pending', 'approved'])
            ->where('data.schema.fields', fn ($fields): bool => data_get(collect($fields)->firstWhere('name', 'status'), 'default') === 'draft')
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'payload' => array_replace($basePayload, [
                'title' => 'Admin MCP Draft Status Payload Event',
                'status' => 'draft',
            ]),
        ])
        ->assertOk();

    $draftEvent = Event::query()->where('title', 'Admin MCP Draft Status Payload Event')->firstOrFail();

    expect((string) $draftEvent->status)->toBe('draft')
        ->and($draftEvent->published_at)->toBeNull();

    AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => (string) $draftEvent->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.schema.fields.0.allowed_values', ['submit_for_moderation'])
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'payload' => array_replace($basePayload, [
                'title' => 'Admin MCP Approved Status Payload Event',
                'status' => 'approved',
            ]),
        ])
        ->assertOk();

    $approvedEvent = Event::query()->where('title', 'Admin MCP Approved Status Payload Event')->firstOrFail();

    expect((string) $approvedEvent->status)->toBe('approved')
        ->and($approvedEvent->published_at)->not->toBeNull();
});

it('surfaces admin event validation failures through MCP write tools', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminMcpEventTerm('domain', 'Admin MCP Validation Domain');
    $disciplineTag = adminMcpEventTerm('discipline', 'Admin MCP Validation Discipline');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'payload' => adminMcpEventPayload([
                'institution' => $institution,
                'speaker' => $speaker,
                'reference' => $reference,
                'series' => $series,
                'domain_tag' => $domainTag,
                'discipline_tag' => $disciplineTag,
            ], [
                'event_type' => [EventType::KuliahCeramah->value],
                'speakers' => [],
            ]),
        ])
        ->assertHasErrors(['Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.']);
});

it('returns default autofill hints and conditional requirement feedback for admin MCP dry runs', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'validate_only' => true,
            'apply_defaults' => true,
            'payload' => [
                'title' => 'AI Feedback Event Preview',
                'event_date' => '2026-06-10',
            ],
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.feedback.validate_only', true)
            ->where('error.details.feedback.apply_defaults', true)
            ->where('error.details.feedback.normalized_payload.timezone', 'Asia/Kuala_Lumpur')
            ->where('error.details.feedback.normalized_payload.event_format', EventFormat::Physical->value)
            ->where('error.details.feedback.normalized_payload.prayer_time', EventPrayerTime::LainWaktu->value)
            ->where('error.details.feedback.issues.0.field', 'custom_time')
            ->where('error.details.feedback.issues.0.severity', 'blocking_error')
            ->where('error.details.feedback.issues.0.required_because.prayer_time', EventPrayerTime::LainWaktu->value)
            ->etc());
});

it('returns enum suggestions for invalid admin MCP dry-run values', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'validate_only' => true,
            'apply_defaults' => true,
            'payload' => [
                'title' => 'AI Feedback Invalid Enum Preview',
                'event_date' => '2026-06-10',
                'custom_time' => '8:30 PM',
                'event_format' => 'physicl',
            ],
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.feedback.issues.0.field', 'event_format')
            ->where('error.details.feedback.issues.0.allowed_values.0', EventFormat::Physical->value)
            ->where('error.details.feedback.issues.0.closest_valid_value', EventFormat::Physical->value)
            ->where('error.details.feedback.issues.0.suggested', EventFormat::Physical->value)
            ->where('error.details.feedback.issues.0.severity', 'blocking_error')
            ->etc());
});

it('returns structured admin MCP validation feedback outside validate-only previews', function () {
    ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->verified()->create();
    $series = Series::factory()->create();
    $domainTag = adminMcpEventTerm('domain', 'Admin MCP Feedback Domain');
    $disciplineTag = adminMcpEventTerm('discipline', 'Admin MCP Feedback Discipline');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'events',
            'payload' => adminMcpEventPayload([
                'institution' => $institution,
                'speaker' => $speaker,
                'reference' => $reference,
                'series' => $series,
                'domain_tag' => $domainTag,
                'discipline_tag' => $disciplineTag,
            ], [
                'event_format' => 'physicl',
            ]),
        ])
        ->assertStructuredContent(fn ($json) => $json
            ->where('error.code', 'validation_error')
            ->where('error.details.feedback.validate_only', false)
            ->where('error.details.feedback.apply_defaults', false)
            ->where('error.details.feedback.issues.0.field', 'event_format')
            ->where('error.details.feedback.issues.0.closest_valid_value', EventFormat::Physical->value)
            ->etc());
});

it('rejects malformed MCP media descriptors through write tools', function () {
    $countryId = ensureMcpMalaysiaCountryExists();

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'speakers',
            'payload' => [
                'name' => 'Speaker With Media',
                'gender' => 'male',
                'is_freelance' => false,
                'status' => 'active',
                'avatar' => 'base64-data',
                'address' => [
                    'country_id' => $countryId,
                ],
            ],
        ])
        ->assertHasErrors(['This MCP media field must be a file descriptor object.']);

    AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'references',
            'payload' => [
                'title' => 'Reference With Media',
                'type' => 'book',
                'status' => 'verified',
                'front_cover' => 'base64-data',
            ],
        ])
        ->assertHasErrors(['This MCP media field must be a file descriptor object.']);
});

it('searches /majlis-style events through the dedicated admin MCP tool', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $matchingEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Admin MCP Majlis Search Match',
        'status' => 'approved',
        'is_muslim_only' => true,
        'starts_at' => Carbon::parse('2026-05-25 12:00:00', 'UTC'),
    ]);

    Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Admin MCP Majlis Search Non Match',
        'status' => 'approved',
        'is_muslim_only' => false,
        'starts_at' => Carbon::parse('2026-05-25 12:00:00', 'UTC'),
    ]);

    // Verify event exists before searching to catch database issues early
    expect(Event::where('is_muslim_only', true)->count())->toBeGreaterThanOrEqual(1);

    AdminServer::actingAs($admin)
        ->tool(AdminSearchEventsTool::class, [
            'is_muslim_only' => true,
            'time_scope' => 'all',
            'per_page' => 10,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.search.query', null)
            ->where('meta.search.sort', 'time')
            ->where('meta.search.filters.is_muslim_only', true)
            ->etc());
});

it('searches events by institution, speaker, and reference through admin-search-events MCP tool', function () {
    $admin = adminMcpUser('super_admin');

    $institution = Institution::factory()->create([
        'name' => 'Markaz Ikhlas MCP Admin',
        'status' => 'verified',
    ]);

    Event::factory()->create([
        'institution_id' => $institution->id,
        'title' => 'MCP Admin Institution Match Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Akram MCP Admin',
        'status' => 'verified',
    ]);

    $speakerEvent = Event::factory()->create([
        'title' => 'MCP Admin Speaker Match Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $speakerEvent->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'name' => $speaker->name,
        'role' => 'speaker',
        'order_column' => 1,
    ]);

    $reference = Reference::factory()->create([
        'title' => 'Kitab MCP Admin Search Reference',
        'status' => 'verified',
    ]);

    $referenceEvent = Event::factory()->create([
        'title' => 'MCP Admin Reference Match Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $referenceEvent->references()->attach($reference->id);

    AdminServer::actingAs($admin)
        ->tool(AdminSearchEventsTool::class, [
            'query' => 'Markaz Ikhlas MCP Admin',
            'time_scope' => 'all',
            'per_page' => 10,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.search.query', 'Markaz Ikhlas MCP Admin')
            ->where('data', fn ($items): bool => collect($items)->pluck('title')->contains('MCP Admin Institution Match Event'))
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminSearchEventsTool::class, [
            'query' => 'Akram MCP Admin',
            'time_scope' => 'all',
            'per_page' => 10,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.search.query', 'Akram MCP Admin')
            ->where('data', fn ($items): bool => collect($items)->pluck('title')->contains('MCP Admin Speaker Match Event'))
            ->etc());

    AdminServer::actingAs($admin)
        ->tool(AdminSearchEventsTool::class, [
            'query' => 'MCP Admin Search Reference',
            'time_scope' => 'all',
            'per_page' => 10,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.search.query', 'MCP Admin Search Reference')
            ->where('data', fn ($items): bool => collect($items)->pluck('title')->contains('MCP Admin Reference Match Event'))
            ->etc());
});

it('creates github issues through the admin MCP tool with Copilot model fallback', function () {
    configureGithubIssueReportingForMcp();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::sequence()
            ->push(['message' => 'Validation Failed', 'errors' => [['field' => 'agent_assignment.model', 'message' => 'Unsupported model']]], 422)
            ->push([
                'number' => 321,
                'title' => '[Bug] Admin MCP GitHub issue',
                'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/321',
                'html_url' => 'https://github.com/AIArmada/ilmu360/issues/321',
            ], 201),
    ]);

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateGitHubIssueTool::class, [
            'category' => 'bug',
            'title' => 'Admin MCP GitHub issue',
            'summary' => 'The admin MCP GitHub issue tool should fall back to the next configured model when the first one is rejected.',
            'platform' => 'chatgpt',
            'client_name' => 'ChatGPT',
            'client_version' => 'GPT-5.4',
            'tool_name' => 'admin-create-github-issue',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.issue.assigned_to_copilot', true)
            ->where('data.issue.copilot_model', 'GPT-5.2-Codex')
            ->where('data.issue.attempted_models', ['GPT-5.4', 'GPT-5.2-Codex'])
            ->etc());

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'agent_assignment.model') === 'GPT-5.4');
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'agent_assignment.model') === 'GPT-5.2-Codex');
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'assignees.0') === 'copilot-swe-agent[bot]');
});

it('creates github issues through the admin MCP tool without Copilot assignment when disabled', function () {
    configureGithubIssueReportingForMcp([
        'admin_copilot_assignment_enabled' => false,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::response([
            'number' => 322,
            'title' => '[Bug] Admin MCP GitHub issue without Copilot',
            'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/322',
            'html_url' => 'https://github.com/AIArmada/ilmu360/issues/322',
        ], 201),
    ]);

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateGitHubIssueTool::class, [
            'category' => 'bug',
            'title' => 'Admin MCP GitHub issue without Copilot',
            'summary' => 'When Copilot assignment is disabled, the admin MCP issue tool should create a plain issue.',
            'platform' => 'chatgpt',
            'client_name' => 'ChatGPT',
            'client_version' => 'GPT-5.4',
            'tool_name' => 'admin-create-github-issue',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.issue.assigned_to_copilot', false)
            ->where('data.issue.copilot_model', null)
            ->where('data.issue.attempted_models', [])
            ->etc());

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->method() === 'POST'
            && (string) $request->url() === 'https://api.github.com/repos/AIArmada/ilmu360/issues'
            && data_get($payload, 'assignees') === null
            && data_get($payload, 'agent_assignment') === null;
    });
});

it('hides the admin github issue tool when github issue reporting is disabled', function () {
    configureGithubIssueReportingForMcp(['enabled' => false]);

    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminCreateGitHubIssueTool::class, [
            'category' => 'bug',
            'title' => 'Hidden tool',
            'summary' => 'This should not be callable when disabled.',
            'platform' => 'chatgpt',
        ])
        ->assertHasErrors(['Tool [admin-create-github-issue] not found.']);
});

it('denies non-admin users from admin MCP tools', function () {
    $user = User::factory()->create();

    AdminServer::actingAs($user)
        ->tool(AdminListResourcesTool::class)
        ->assertHasErrors(['Forbidden.']);

    AdminServer::actingAs($user)
        ->tool(AdminSearchEventsTool::class, [
            'time_scope' => 'all',
            'per_page' => 5,
        ])
        ->assertHasErrors(['Forbidden.']);
});

it('serves an authenticated event stream compatibility endpoint for /mcp/admin', function () {
    $admin = adminMcpUser('super_admin');
    $token = $admin->createToken('mcp-http-test')->plainTextToken;

    $response = $this->withToken($token)
        ->get('/mcp/admin');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/event-stream');
    expect($response->streamedContent())->toContain(': keep-alive');
});

it('returns a bearer-auth challenge for unauthenticated MCP stream requests', function () {
    $response = $this->withHeaders([
        'Accept' => 'text/event-stream',
    ])->get('/mcp/admin');

    $response->assertUnauthorized();
    $response->assertHeader('WWW-Authenticate');
    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="mcp"');
});

it('exposes OAuth metadata for MCP clients', function () {
    $this->getJson('/.well-known/oauth-authorization-server/mcp/admin')
        ->assertOk()
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/mcp/register'))
        ->assertJsonPath('scopes_supported.0', 'mcp:use');

    $this->getJson('/.well-known/oauth-protected-resource/mcp/admin')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp/admin'))
        ->assertJsonPath('authorization_servers.0', url('/'))
        ->assertJsonPath('scopes_supported.0', 'mcp:use');
});

it('only registers OAuth clients for allowed redirect domains and schemes', function () {
    config()->set('mcp.redirect_domains', [
        'https://ilmu360.test',
        'https://chatgpt.com',
        'http://localhost',
    ]);
    config()->set('mcp.custom_schemes', ['vscode']);

    $this->postJson('/oauth/mcp/register', [
        'client_name' => 'ChatGPT',
        'redirect_uris' => ['https://chatgpt.com/connector/oauth/callback-123'],
    ])->assertOk()
        ->assertJsonPath('redirect_uris.0', 'https://chatgpt.com/connector/oauth/callback-123')
        ->assertJsonPath('scope', 'mcp:use');

    $this->postJson('/oauth/mcp/register', [
        'client_name' => 'Prefix Attack',
        'redirect_uris' => ['https://chatgpt.com.evil.example/callback'],
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');

    $this->postJson('/oauth/mcp/register', [
        'client_name' => 'VS Code',
        'redirect_uris' => ['vscode://copilot/mcp-callback'],
    ])->assertOk()
        ->assertJsonPath('redirect_uris.0', 'vscode://copilot/mcp-callback');

    $this->postJson('/oauth/mcp/register', [
        'client_name' => 'Blocked Host',
        'redirect_uris' => ['https://evil.example/callback'],
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');

    $this->postJson('/oauth/mcp/register', [
        'client_name' => 'Blocked Scheme',
        'redirect_uris' => ['cursor://mcp-callback'],
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('serves the admin MCP stream for Passport-authenticated admin users', function () {
    $admin = adminMcpUser('super_admin');

    Passport::actingAs(adminPassportUser($admin), ['mcp:use']);

    $response = $this->get('/mcp/admin');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/event-stream');
    expect($response->streamedContent())->toContain(': keep-alive');
});

it('initializes and lists admin MCP tools over the HTTP endpoint for Passport-authenticated admins', function () {
    configureGithubIssueReportingForMcp();

    $admin = adminMcpUser('super_admin');

    Passport::actingAs(adminPassportUser($admin), ['mcp:use']);

    $initialize = $this->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-passport',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    expect($initialize->json('result.instructions'))->toContain('institution-type nouns (`masjid`, `surau`, `madrasah`, `maahad`, `pondok`, `sekolah`, `kolej`, `universiti`) should be searched as `institutions` first')
        ->toContain('venue-type nouns (`dewan`, `auditorium`, `stadium`, `perpustakaan`, `padang`, `hotel`) should be searched as `venues` first')
        ->toContain('`spaces` are finer-grained sublocations inside institutions');

    $sessionId = $initialize->headers->get('MCP-Session-Id');

    expect($sessionId)->not->toBeNull();

    $listTools = $this->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'list-tools-admin-mcp-passport',
        'method' => 'tools/list',
        'params' => [
            'per_page' => 50,
        ],
    ])->assertOk();

    $tools = collect($listTools->json('result.tools'))->keyBy('name');

    expect($tools->keys()->all())->toContain(
        'search',
        'fetch',
        'admin-list-resources',
        'admin-get-resource-meta',
        'admin-search-events',
        'admin-list-records',
        'admin-get-record',
        'admin-get-record-actions',
        'admin-upload-event-cover-image',
        'admin-upload-event-poster-image',
        'admin-get-write-schema',
        'admin-get-event-moderation-schema',
        'admin-get-report-triage-schema',
        'admin-get-contribution-request-review-schema',
        'admin-get-membership-application-review-schema',
        'admin-create-event',
        'admin-batch-create-events',
        'admin-update-event',
        'admin-batch-update-events',
        'admin-create-record',
        'admin-batch-create-records',
        'admin-batch-update-records',
        'admin-create-github-issue',
        'admin-moderate-event',
        'admin-triage-report',
        'admin-review-contribution-request',
        'admin-review-membership-application',
        'admin-update-record',
    );

    expect($tools->keys()->all())->not->toContain('admin-generate-event-cover-image', 'admin-generate-event-poster-image');

    expect($tools->keys()->all())->not->toContain('member-list-resources', 'member-list-records');

    expect($tools->keys()->all())->not->toContain(
        'admin-delete-record',
        'admin-delete',
        'admin-remove-record',
    );

    expect($tools->get('search')['securitySchemes'] ?? [])->toContainEqual([
        'type' => 'oauth2',
        'scopes' => ['mcp:use'],
    ]);

    expect($tools->get('fetch')['securitySchemes'] ?? [])->toContainEqual([
        'type' => 'oauth2',
        'scopes' => ['mcp:use'],
    ])
        ->and($tools->get('fetch')['description'] ?? '')->toContain('not a url or file:// resource URI')
        ->and(data_get($tools->get('fetch'), 'inputSchema.properties.id.description'))->toContain('Do not pass the document url');

    expect(data_get($tools->get('search'), 'outputSchema.type'))->toBe('object');
    expect(data_get($tools->get('search'), 'outputSchema.required'))->toBe(['results']);
    expect(data_get($tools->get('search'), 'outputSchema.properties.results.type'))->toBe('array');
    expect(data_get($tools->get('search'), 'outputSchema.properties.results.items.required'))->toBe(['id', 'title', 'url']);
    expect(data_get($tools->get('search'), 'outputSchema.properties.results.items.properties.url.format'))->toBe('uri');

    expect(data_get($tools->get('fetch'), 'outputSchema.type'))->toBe('object');
    expect(data_get($tools->get('fetch'), 'outputSchema.required'))->toBe(['id', 'title', 'text', 'url', 'metadata']);
    expect(data_get($tools->get('fetch'), 'outputSchema.properties.metadata.type'))->toBe('object');
    expect(data_get($tools->get('fetch'), 'outputSchema.properties.metadata.required'))->toBe(['description', 'mime_type', 'resource_uri', 'relative_path', 'last_modified']);
    expect(data_get($tools->get('fetch'), 'outputSchema.properties.metadata.properties.last_modified.format'))->toBe('date-time');

    expect($tools->get('admin-list-resources')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
    ]);

    $readOnlyTools = $tools->filter(fn (array $tool): bool => data_get($tool, 'annotations.readOnlyHint') === true);

    expect($readOnlyTools->isNotEmpty())->toBeTrue()
        ->and($readOnlyTools->every(fn (array $tool): bool => data_get($tool, 'annotations.destructiveHint') === false))->toBeTrue()
        ->and($readOnlyTools->every(fn (array $tool): bool => data_get($tool, 'annotations.openWorldHint') === false))->toBeTrue();

    expect(collect((array) data_get($tools->get('admin-list-records'), 'inputSchema.properties.filters.type'))->contains('object'))->toBeTrue();

    expect($tools->get('admin-get-write-schema')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('admin-get-event-moderation-schema')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('admin-create-record')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('admin-create-event')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);
    expect(data_get($tools->get('admin-create-event'), 'title'))->toBe('Create Event');
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.required'))->contains('title'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.required'))->contains('event_date'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.required'))->contains('prayer_time'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.required'))->contains('event_type'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.properties.primary_organizer_key.type'))->contains('string'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.properties.institution_key.type'))->contains('string'))->toBeTrue();
    expect(data_get($tools->get('admin-create-event'), 'inputSchema.properties.organizer_key'))->toBeNull();
    expect(data_get($tools->get('admin-create-event'), 'inputSchema.properties.organizer_id'))->toBeNull();
    expect(data_get($tools->get('admin-create-event'), 'inputSchema.properties.institution_id'))->toBeNull();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.properties.speaker_keys.type'))->contains('array'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.properties.reference_keys.type'))->contains('array'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-create-event'), 'inputSchema.properties.languages.type'))->contains('array'))->toBeTrue();
    expect((string) data_get($tools->get('admin-create-event'), 'inputSchema.properties.apply_defaults.description'))
        ->toContain('Preview-only helper')
        ->toContain('ignored for persisted creates');
    expect((string) data_get($tools->get('admin-update-event'), 'description'))
        ->toContain('MCP-only event wrapper')
        ->toContain('pass [] to detach all')
        ->toContain('pass null to preserve');
    expect((string) data_get($tools->get('admin-update-event'), 'inputSchema.properties.speaker_keys.description'))
        ->toContain('Omit or pass null to preserve')
        ->toContain('Pass [] to detach all speakers');
    expect((string) data_get($tools->get('admin-batch-create-events'), 'description'))
        ->toContain('apply_defaults is only honored together with validate_only=true')
        ->toContain('ignored for real creates');

    expect(data_get($tools->get('admin-create-record'), 'inputSchema.properties.payload.type'))->toBe('object');
    expect(collect((array) data_get($tools->get('admin-create-record'), 'inputSchema.required'))->contains('payload'))->toBeTrue();

    expect(data_get($tools->get('admin-update-record'), 'inputSchema.properties.payload.type'))->toBe('object');
    expect(collect((array) data_get($tools->get('admin-update-record'), 'inputSchema.required'))->contains('record_key'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-update-record'), 'inputSchema.required'))->contains('payload'))->toBeTrue();

    expect(data_get($tools->get('admin-batch-create-records'), 'inputSchema.properties.items.items.properties.payload.type'))->toBe('object');
    expect(data_get($tools->get('admin-batch-create-records'), 'inputSchema.properties.items.items.properties.payload.additionalProperties'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-batch-create-records'), 'inputSchema.properties.items.items.required'))->contains('payload'))->toBeTrue();

    expect(data_get($tools->get('admin-batch-update-records'), 'inputSchema.properties.items.items.properties.payload.type'))->toBe('object');
    expect(data_get($tools->get('admin-batch-update-records'), 'inputSchema.properties.items.items.properties.payload.additionalProperties'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-batch-update-records'), 'inputSchema.properties.items.items.required'))->contains('record_key'))->toBeTrue();
    expect(collect((array) data_get($tools->get('admin-batch-update-records'), 'inputSchema.properties.items.items.required'))->contains('payload'))->toBeTrue();

    expect($tools->get('admin-create-github-issue')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => true,
    ]);

    $githubIssueCategorySchema = data_get($tools->get('admin-create-github-issue'), 'inputSchema.properties.category');

    expect($githubIssueCategorySchema['enum'] ?? null)->toBe(GitHubIssueReportContract::categories())
        ->and($githubIssueCategorySchema['default'] ?? null)->toBe(GitHubIssueReportContract::DEFAULT_CATEGORY)
        ->and((string) ($githubIssueCategorySchema['description'] ?? ''))
        ->toContain('bug', 'docs_mismatch', 'proposal', 'feature_request', 'parameter_change', 'other');
});

it('rejects Passport-authenticated users without admin access on the admin MCP stream endpoint', function () {
    $user = User::factory()->create();

    Passport::actingAs(adminPassportUser($user), ['mcp:use']);

    $this->get('/mcp/admin')->assertForbidden();
});

it('rejects member-scoped tokens on the admin MCP stream endpoint even for dual-scope users', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    addTestMember($institution, $admin, 'admin');

    $token = $admin->createToken('mcp-member-only', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $this->withToken($token)
        ->get('/mcp/admin')
        ->assertForbidden();
});

it('initializes and lists admin MCP tools over the HTTP endpoint', function () {
    configureGithubIssueReportingForMcp();

    $admin = adminMcpUser('super_admin');
    $token = $admin->createToken('mcp-http-test')->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $sessionId = $initialize->headers->get('MCP-Session-Id');

    expect($sessionId)->not->toBeNull();

    $listTools = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'list-tools-admin-mcp',
        'method' => 'tools/list',
        'params' => [
            'per_page' => 50,
        ],
    ])->assertOk();

    $toolNames = collect($listTools->json('result.tools'))->pluck('name')->all();

    expect($toolNames)->toContain(
        'search',
        'fetch',
        'admin-list-resources',
        'admin-get-resource-meta',
        'admin-search-events',
        'admin-list-records',
        'admin-get-record',
        'admin-get-record-actions',
        'admin-upload-event-cover-image',
        'admin-upload-event-poster-image',
        'admin-get-write-schema',
        'admin-get-event-moderation-schema',
        'admin-get-report-triage-schema',
        'admin-get-contribution-request-review-schema',
        'admin-get-membership-application-review-schema',
        'admin-create-event',
        'admin-batch-create-events',
        'admin-update-event',
        'admin-batch-update-events',
        'admin-create-record',
        'admin-create-github-issue',
        'admin-moderate-event',
        'admin-triage-report',
        'admin-review-contribution-request',
        'admin-review-membership-application',
        'admin-update-record',
    );

    expect($toolNames)->not->toContain('admin-generate-event-cover-image', 'admin-generate-event-poster-image');

    expect($toolNames)->not->toContain(
        'admin-delete-record',
        'admin-delete',
        'admin-remove-record',
    );
});

it('searches and fetches verified documentation through admin MCP tools', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationSearchTool::class, [
            'query' => 'capability matrix venues',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-admin-mcp-guide'
                    && ($result['title'] ?? null) === 'ilmu360° Admin MCP Agent Guide'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_admin_agent_guide.md'
            ))
            ->etc())
        ->assertSee([
            'docs-admin-mcp-guide',
            'ilmu360° Admin MCP Agent Guide',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationSearchTool::class, [
            'query' => 'csv json correction duplicate check validate only chunk',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-admin-event-csv-json-create-guide'
                    && ($result['title'] ?? null) === 'ilmu360° MCP CSV / JSON Event Creation Playbook'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_event_csv_json_creation_guide.md'
            ))
            ->etc())
        ->assertSee([
            'docs-admin-event-csv-json-create-guide',
            'ilmu360° MCP CSV / JSON Event Creation Playbook',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationFetchTool::class, [
            'id' => 'docs-admin-mcp-guide',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-admin-mcp-guide')
            ->where('title', 'ilmu360° Admin MCP Agent Guide')
            ->where('url', 'file://docs/ilmu360_mcp_admin_agent_guide.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_mcp_admin_agent_guide.md')
            ->where('metadata.relative_path', 'docs/ilmu360_mcp_admin_agent_guide.md')
            ->where('text', fn (string $text): bool => str_contains($text, '## MCP capability matrix'))
            ->etc())
        ->assertSee([
            'docs-admin-mcp-guide',
            '# ilmu360° Admin MCP Agent Guide',
            '## MCP capability matrix',
            'Tool-centric clients like ChatGPT and the OpenAI Responses MCP integration import tools from `tools/list`',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationFetchTool::class, [
            'id' => 'docs-admin-event-csv-json-create-guide',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-admin-event-csv-json-create-guide')
            ->where('title', 'ilmu360° MCP CSV / JSON Event Creation Playbook')
            ->where('url', 'file://docs/ilmu360_mcp_event_csv_json_creation_guide.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_mcp_event_csv_json_creation_guide.md')
            ->where('metadata.relative_path', 'docs/ilmu360_mcp_event_csv_json_creation_guide.md')
            ->where('text', fn (string $text): bool => str_contains($text, '## Source-of-truth precedence'))
            ->etc())
        ->assertSee([
            'docs-admin-event-csv-json-create-guide',
            '# ilmu360° MCP CSV / JSON Event Creation Playbook',
            '## Source-of-truth precedence',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationSearchTool::class, [
            'query' => 'tool examples',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-mcp-tool-examples'
                    && ($result['title'] ?? null) === 'ilmu360° MCP Tool Examples'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_tool_examples.md'
            ))
            ->etc())
        ->assertSee([
            'docs-mcp-tool-examples',
            'ilmu360° MCP Tool Examples',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationFetchTool::class, [
            'id' => 'docs-technical-documentation',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-technical-documentation')
            ->where('title', 'ilmu360° Developer Technical Documentation')
            ->where('url', 'file://docs/ilmu360_technical_documentation.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_technical_documentation.md')
            ->where('metadata.relative_path', 'docs/ilmu360_technical_documentation.md')
            ->where('text', fn (string $text): bool => str_contains($text, '## 1. System Overview'))
            ->etc())
        ->assertSee([
            'docs-technical-documentation',
            '# ilmu360° Developer Technical Documentation',
            '## 1. System Overview',
        ]);
});

it('auto-injects guide on first operational call and allows the retry in the same MCP session', function () {
    $admin = adminMcpUser('super_admin');
    $token = $admin->createToken('mcp-admin-preflight-test')->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-preflight',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $sessionId = $initialize->headers->get('MCP-Session-Id');

    expect($sessionId)->not->toBeNull();

    $firstCall = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-first-attempt',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($firstCall->json('result.isError'))->toBeFalse();
    expect($firstCall->json('result.structuredContent.action'))->toBe('documentation_preflight_injected');
    expect($firstCall->json('result.structuredContent.document.id'))->toBe(McpDocumentationPreflight::GUIDE_DOCUMENT_ID);
    expect($firstCall->json('result.structuredContent.retry.tool_name'))->toBe('admin-list-resources');
    expect($firstCall->json('result.content.0.text'))->toContain('Guide auto-loaded');

    $allowedCall = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-after-docs',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($allowedCall->json('result.isError'))->toBeFalse();
    expect($allowedCall->json('result.structuredContent.data.resources'))->toBeArray();
});

it('auto-injects guide via meta-session identity and allows the retry', function () {
    $admin = adminMcpUser('super_admin');
    $token = $admin->createToken('mcp-admin-preflight-meta-session-test')->plainTextToken;
    $metaSessionId = 'meta-session-'.Str::lower((string) Str::uuid());

    $initialize = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-preflight-meta-session',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    expect($initialize->headers->get('MCP-Session-Id'))->not->toBeNull();

    $firstCall = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-without-header-session',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
            '_meta' => [
                'session' => [
                    'id' => $metaSessionId,
                ],
            ],
        ],
    ])->assertOk();

    expect($firstCall->json('result.isError'))->toBeFalse();
    expect($firstCall->json('result.structuredContent.action'))->toBe('documentation_preflight_injected');
    expect($firstCall->json('result.structuredContent.document.id'))->toBe(McpDocumentationPreflight::GUIDE_DOCUMENT_ID);

    $allowedCall = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-after-meta-session-fetch',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
            '_meta' => [
                'session' => [
                    'id' => $metaSessionId,
                ],
            ],
        ],
    ])->assertOk();

    expect($allowedCall->json('result.isError'))->toBeFalse();
    expect($allowedCall->json('result.structuredContent.data.resources'))->toBeArray();
});

it('auto-injects guide when no session identity is forwarded and allows the retry', function () {
    $admin = adminMcpUser('super_admin');
    $token = $admin->createToken('mcp-admin-preflight-user-fallback-test')->plainTextToken;

    $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-preflight-user-fallback',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $firstCall = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-without-session-identity',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($firstCall->json('result.isError'))->toBeFalse();
    expect($firstCall->json('result.structuredContent.action'))->toBe('documentation_preflight_injected');
    expect($firstCall->json('result.structuredContent.document.id'))->toBe(McpDocumentationPreflight::GUIDE_DOCUMENT_ID);

    $allowedCall = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'call-admin-list-resources-after-fallback-fetch',
        'method' => 'tools/call',
        'params' => [
            'name' => 'admin-list-resources',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($allowedCall->json('result.isError'))->toBeFalse();
    expect($allowedCall->json('result.structuredContent.data.resources'))->toBeArray();
});

it('lists and reads the documentation routing prompt through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->prompt(DocumentationToolRoutingPrompt::class, [
            'topic' => 'crud',
        ])
        ->assertOk()
        ->assertName('documentation-tool-routing')
        ->assertTitle('Documentation Tool Routing')
        ->assertSee([
            'Use the verified documentation tools like this:',
            'Before the first ilmu360 admin MCP operational tool call for runtime reads, search, lookup, listing, metadata inspection, relation traversal, write-schema discovery, preview, write, or workflow actions, ensure the verified guide is already in context.',
            'Use `fetch` first',
            'Search `institutions` first when the noun matches an institution type',
            'Topic-specific guidance for "crud":',
            'Fetch `docs-admin-mcp-guide` and focus on the MCP capability matrix, writable resource matrix, and preview sections.',
        ]);

    $token = $admin->createToken('mcp-admin-prompt-list-test')->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-prompts',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $sessionId = $initialize->headers->get('MCP-Session-Id');

    expect($sessionId)->not->toBeNull();

    $listPrompts = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'list-admin-mcp-prompts',
        'method' => 'prompts/list',
        'params' => [],
    ])->assertOk();

    $prompts = collect($listPrompts->json('result.prompts'))->keyBy('name');

    expect($prompts->keys()->all())->toContain('documentation-tool-routing');
    expect($prompts->get('documentation-tool-routing'))->toMatchArray([
        'title' => 'Documentation Tool Routing',
        'description' => 'Short guidance for deciding when to use the verified admin documentation search and fetch tools exposed by this server, with an optional topic hint for more targeted advice.',
        'arguments' => [
            [
                'name' => 'topic',
                'description' => 'Optional focus area such as crud, auth, media uploads, runtime records, entity selection, search, or fetch.',
                'required' => false,
            ],
        ],
    ]);

    $getPrompt = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'get-admin-mcp-prompt',
        'method' => 'prompts/get',
        'params' => [
            'name' => 'documentation-tool-routing',
            'arguments' => [
                'topic' => 'crud',
            ],
        ],
    ])->assertOk();

    expect($getPrompt->json('result.description'))->toBe('Short guidance for deciding when to use the verified admin documentation search and fetch tools exposed by this server, with an optional topic hint for more targeted advice.');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Before the first ilmu360 admin MCP operational tool call for runtime reads, search, lookup, listing, metadata inspection, relation traversal, write-schema discovery, preview, write, or workflow actions, ensure the verified guide is already in context.');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('a fresh docs fetch may be skipped only when the fetched guide is already active in context');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Use `fetch` first');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Search `institutions` first when the noun matches an institution type');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Topic-specific guidance for "crud":');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Fetch `docs-admin-mcp-guide` and focus on the MCP capability matrix, writable resource matrix, and preview sections.');

    AdminServer::actingAs($admin)
        ->completion(DocumentationToolRoutingPrompt::class, 'topic', 'cr')
        ->assertOk()
        ->assertCompletionValues(['crud']);
});

it('lists and reads verified documentation resources through the admin MCP server', function () {
    $admin = adminMcpUser('super_admin');

    AdminServer::actingAs($admin)
        ->resource(McpGuideResource::class)
        ->assertOk()
        ->assertName('docs-admin-mcp-guide')
        ->assertTitle('ilmu360° Admin MCP Agent Guide')
        ->assertSee([
            '# ilmu360° Admin MCP Agent Guide',
            'Verified Documentation Resource',
            '## MCP capability matrix',
            '## Writable resource matrix',
            '## Entity selection heuristics for record search',
            '## Quick search playbook',
            'Current structurally write-capable admin resources include:',
            '- `venues`',
        ]);

    $token = $admin->createToken('mcp-admin-resource-list-test')->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-admin-mcp-resources',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'Pest',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $sessionId = $initialize->headers->get('MCP-Session-Id');

    expect($sessionId)->not->toBeNull();

    $listResources = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 'list-admin-mcp-resources',
        'method' => 'resources/list',
        'params' => [],
    ])->assertOk();

    $resources = collect($listResources->json('result.resources'))->keyBy('name');

    expect($resources->keys()->all())->toContain('docs-admin-mcp-guide');
    expect($resources->keys()->all())->not->toContain('docs-crud-capability-matrix');
    expect($resources->get('docs-admin-mcp-guide'))->toMatchArray([
        'uri' => 'file://docs/ilmu360_mcp_admin_agent_guide.md',
        'mimeType' => 'text/markdown',
    ]);
});

function ensureMcpMalaysiaCountryExists(): string
{
    return (string) ensureTestMalaysiaCountry()->getKey();
}

function adminPassportUser(User $user): PassportUser
{
    return PassportUser::query()->findOrFail($user->getKey());
}

function adminMcpUser(string $role): User
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

    return $user->fresh();
}

/**
 * @return array{filename: string, mime_type: string, content_base64: string}
 */
function adminMcpImageDescriptor(string $name): array
{
    $upload = fakeGeneratedImageUpload($name, 640, 480);
    $contents = file_get_contents((string) $upload->getRealPath());

    if (! is_string($contents) || $contents === '') {
        throw new RuntimeException('Unable to create MCP image descriptor.');
    }

    return [
        'filename' => $name,
        'mime_type' => 'image/png',
        'content_base64' => base64_encode($contents),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function configureGithubIssueReportingForMcp(array $overrides = []): void
{
    config()->set('services.github.issues', array_replace([
        'enabled' => true,
        'token' => 'github-token',
        'api_base' => 'https://api.github.com',
        'api_version' => '2026-03-10',
        'repository_owner' => 'AIArmada',
        'repository_name' => 'ilmu360',
        'base_branch' => 'main',
        'custom_agent' => null,
        'custom_instructions' => 'Use repository tests and conventions when following up.',
        'admin_model' => 'GPT-5.4',
        'admin_model_fallbacks' => ['GPT-5.2-Codex', 'Auto'],
        'admin_copilot_assignment_enabled' => true,
        'copilot_assignee' => 'copilot-swe-agent[bot]',
    ], $overrides));
}

function adminMcpEventTerm(string $taxonomyCode, string $name): EventTerm
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

/**
 * @param  array{
 *     institution: Institution,
 *     speaker: Speaker,
 *     reference: Reference,
 *     series: Series,
 *     domain_tag: EventTerm,
 *     discipline_tag: EventTerm
 * }  $fixtures
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminMcpEventPayload(array $fixtures, array $overrides = []): array
{
    return array_replace([
        'title' => 'Admin MCP Event Created',
        'event_date' => '2026-05-27',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:00',
        'end_time' => '22:00',
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_format' => EventFormat::Hybrid->value,
        'visibility' => EventVisibility::Public->value,
        'event_url' => 'https://example.com/events/admin-mcp-event-created',
        'live_url' => null,
        'recording_url' => 'https://example.com/recordings/admin-mcp-event-created',
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'is_muslim_only' => true,
        'event_type' => [EventType::Other->value],
        'domain_tags' => [(string) $fixtures['domain_tag']->getKey()],
        'discipline_tags' => [(string) $fixtures['discipline_tag']->getKey()],
        'source_tags' => [],
        'issue_tags' => [],
        'references' => [(string) $fixtures['reference']->getKey()],
        'primary_organizer_id' => (string) $fixtures['institution']->getKey(),
        'institution_id' => (string) $fixtures['institution']->getKey(),
        'series' => [(string) $fixtures['series']->getKey()],
        'speakers' => [(string) $fixtures['speaker']->getKey()],
        'other_key_people' => [
            [
                'role' => 'moderator',
                'name' => 'Admin MCP Moderator',
                'is_public' => true,
                'notes' => 'Will host the session.',
            ],
        ],
        'registration_required' => true,
        'status' => 'draft',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function adminMcpStableEvent(array $overrides = []): Event
{
    $startsAt = now()->addDays(14)->setTimezone('UTC')->startOfHour();

    $event = Event::factory()->create(array_replace([
        'institution_id' => Institution::factory(),
        'title' => 'Admin MCP Stable Event',
        'description' => null,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'draft',
        'live_url' => null,
        'recording_url' => null,
        'is_muslim_only' => true,
    ], $overrides));

    $event->setPrimaryOrganizer(Institution::query()->findOrFail((string) $event->institution_id));

    $event->accessPolicy()->delete();

    return $event->fresh();
}

it('batch-creates admin resource records via the admin-batch-create-records MCP tool', function () {
    $admin = adminMcpUser('super_admin');
    $countryId = ensureMcpMalaysiaCountryExists();

    AdminServer::actingAs($admin)
        ->tool(AdminBatchCreateRecordsTool::class, [
            'resource_key' => 'speakers',
            'items' => [
                [
                    'external_row_id' => 'mcp-row-1',
                    'payload' => [
                        'name' => 'MCP Batch Speaker Alpha',
                        'gender' => 'male',
                        'status' => 'verified',
                        'address' => [
                            'country_id' => $countryId,
                        ],
                    ],
                ],
                [
                    'external_row_id' => 'mcp-row-2',
                    'payload' => [
                        'name' => 'MCP Batch Speaker Beta',
                        'gender' => 'female',
                        'status' => 'verified',
                        'address' => [
                            'country_id' => $countryId,
                        ],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.summary.total', 2)
            ->where('data.summary.created', 2)
            ->where('data.summary.validation_failed', 0)
            ->where('data.validate_only', false)
            ->has('data.results', 2)
            ->where('data.results.0.status', 'created')
            ->where('data.results.0.external_row_id', 'mcp-row-1')
            ->where('data.results.1.status', 'created')
            ->where('data.results.1.external_row_id', 'mcp-row-2')
            ->etc()
        );

    $this->assertDatabaseHas('speakers', ['name' => 'MCP Batch Speaker Alpha']);
    $this->assertDatabaseHas('speakers', ['name' => 'MCP Batch Speaker Beta']);
});

it('batch-creates records with validate_only via the admin-batch-create-records MCP tool without persisting', function () {
    $admin = adminMcpUser('super_admin');
    $countryId = ensureMcpMalaysiaCountryExists();

    AdminServer::actingAs($admin)
        ->tool(AdminBatchCreateRecordsTool::class, [
            'resource_key' => 'speakers',
            'items' => [
                [
                    'payload' => [
                        'name' => 'MCP Dry Run Speaker',
                        'gender' => 'male',
                        'status' => 'verified',
                        'address' => [
                            'country_id' => $countryId,
                        ],
                    ],
                ],
            ],
            'validate_only' => true,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.validate_only', true)
            ->where('data.results.0.status', 'preview')
            ->etc()
        );

    $this->assertDatabaseMissing('speakers', ['name' => 'MCP Dry Run Speaker']);
});

it('batch-updates admin resource records via the admin-batch-update-records MCP tool', function () {
    $admin = adminMcpUser('super_admin');

    $speaker = Speaker::factory()->create([
        'name' => 'MCP Batch Update Before',
        'status' => 'pending',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchUpdateRecordsTool::class, [
            'resource_key' => 'speakers',
            'items' => [
                [
                    'record_key' => (string) $speaker->getKey(),
                    'external_row_id' => 'mcp-update-1',
                    'payload' => [
                        'name' => 'MCP Batch Update After',
                        'gender' => 'male',
                        'status' => 'verified',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.summary.total', 1)
            ->where('data.summary.updated', 1)
            ->where('data.results.0.status', 'updated')
            ->where('data.results.0.external_row_id', 'mcp-update-1')
            ->etc()
        );

    $this->assertDatabaseHas('speakers', ['name' => 'MCP Batch Update After']);
});

it('batch-creates events via the admin-batch-create-events MCP tool with speaker_keys and reference_keys resolved', function () {
    $admin = adminMcpUser('super_admin');

    $speaker = Speaker::factory()->create([
        'name' => 'MCP Batch Event Speaker',
        'slug' => 'mcp-batch-event-speaker',
        'gender' => 'male',
        'status' => 'active',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'MCP Batch Institution',
        'slug' => 'mcp-batch-institution',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchCreateEventsTool::class, [
            'apply_defaults' => true,
            'items' => [
                [
                    'external_row_id' => 'event-row-1',
                    'title' => 'MCP Batch Event Alpha',
                    'event_date' => '2026-07-15',
                    'prayer_time' => EventPrayerTime::LainWaktu->value,
                    'custom_time' => '20:00',
                    'timezone' => 'Asia/Kuala_Lumpur',
                    'event_format' => EventFormat::Physical->value,
                    'visibility' => EventVisibility::Public->value,
                    'gender' => EventGenderRestriction::All->value,
                    'age_group' => [EventAgeGroup::AllAges->value],
                    'event_type' => [EventType::Other->value],
                    'primary_organizer_key' => $institution->slug,
                    'institution_key' => $institution->slug,
                    'speaker_keys' => [$speaker->slug],
                    'status' => 'draft',
                ],
                [
                    'external_row_id' => 'event-row-2',
                    'title' => 'MCP Batch Event Beta',
                    'event_date' => '2026-07-16',
                    'prayer_time' => EventPrayerTime::LainWaktu->value,
                    'custom_time' => '21:00',
                    'timezone' => 'Asia/Kuala_Lumpur',
                    'event_format' => EventFormat::Physical->value,
                    'visibility' => EventVisibility::Public->value,
                    'gender' => EventGenderRestriction::All->value,
                    'age_group' => [EventAgeGroup::AllAges->value],
                    'event_type' => [EventType::Other->value],
                    'primary_organizer_key' => $institution->slug,
                    'status' => 'draft',
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.summary.total', 2)
            ->where('data.summary.created', 2)
            ->where('data.results.0.status', 'created')
            ->where('data.results.0.external_row_id', 'event-row-1')
            ->where('data.results.1.status', 'created')
            ->where('data.results.1.external_row_id', 'event-row-2')
            ->etc()
        );

    $this->assertDatabaseHas('events', ['title' => 'MCP Batch Event Alpha']);
    $this->assertDatabaseHas('events', ['title' => 'MCP Batch Event Beta']);
});

it('does not apply schema defaults during persisted admin-batch-create-events writes', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'slug' => 'mcp-batch-defaults-institution',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchCreateEventsTool::class, [
            'apply_defaults' => true,
            'items' => [
                [
                    'external_row_id' => 'missing-default-fields',
                    'title' => 'MCP Batch Event Missing Persisted Defaults',
                    'event_date' => '2026-07-17',
                    'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
                    'event_type' => [EventType::Other->value],
                    'primary_organizer_key' => $institution->slug,
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.summary.total', 1)
            ->where('data.summary.validation_failed', 1)
            ->where('data.results.0.status', 'validation_failed')
            ->where('data.results.0.external_row_id', 'missing-default-fields')
            ->has('data.results.0.errors.timezone')
            ->has('data.results.0.errors.event_format')
            ->etc()
        );

    $this->assertDatabaseMissing('events', ['title' => 'MCP Batch Event Missing Persisted Defaults']);
});

it('batch-creates events with validate_only via admin-batch-create-events without persisting', function () {
    $admin = adminMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'slug' => 'mcp-batch-dry-run-institution',
        'status' => 'verified',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchCreateEventsTool::class, [
            'apply_defaults' => true,
            'items' => [
                [
                    'title' => 'MCP Batch Dry Run Event',
                    'event_date' => '2026-08-01',
                    'prayer_time' => EventPrayerTime::LainWaktu->value,
                    'custom_time' => '20:00',
                    'event_type' => [EventType::Other->value],
                    'primary_organizer_key' => $institution->slug,
                    'registration_mode' => RegistrationScope::Event->value,
                    'status' => 'draft',
                ],
            ],
            'validate_only' => true,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.validate_only', true)
            ->where('data.results.0.status', 'preview')
            ->etc()
        );

    $this->assertDatabaseMissing('events', ['title' => 'MCP Batch Dry Run Event']);
});

it('updates an event via the admin-update-event MCP tool with speaker_keys resolved', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Original Event Title',
    ]);

    $speaker = Speaker::factory()->create(['slug' => 'test-update-speaker']);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateEventTool::class, [
            'event_key' => $event->getRouteKey(),
            'title' => 'Updated Event Title',
            'speaker_keys' => [$speaker->slug],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.title', 'Updated Event Title')
            ->etc()
        );

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => 'Updated Event Title',
    ]);

    expect($event->refresh()->speakers->contains($speaker))->toBeTrue();
});

it('detaches speakers and references when empty route-key arrays are provided via admin-update-event', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Event With Existing Relations',
    ]);

    $speaker = Speaker::factory()->create(['slug' => 'detach-update-speaker']);
    $reference = Reference::factory()->create(['slug' => 'detach-update-reference']);

    $event->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'role' => 'speaker',
        'order_column' => 1,
    ]);
    $event->references()->attach($reference->id);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateEventTool::class, [
            'event_key' => $event->getRouteKey(),
            'speaker_keys' => [],
            'reference_keys' => [],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.title', 'Event With Existing Relations')
            ->etc()
        );

    $event->refresh()->load(['speakers', 'references']);

    expect($event->speakers)->toHaveCount(0)
        ->and($event->references)->toHaveCount(0);
});

it('preserves speakers and references when route-key arrays are omitted via admin-update-event', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Event Preserve Existing Relations',
    ]);

    $speaker = Speaker::factory()->create(['slug' => 'preserve-update-speaker']);
    $reference = Reference::factory()->create(['slug' => 'preserve-update-reference']);

    $event->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'role' => 'speaker',
        'order_column' => 1,
    ]);
    $event->references()->attach($reference->id);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateEventTool::class, [
            'event_key' => $event->getRouteKey(),
            'title' => 'Event Preserve Existing Relations Updated',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.title', 'Event Preserve Existing Relations Updated')
            ->etc()
        );

    $event->refresh()->load(['speakers', 'references']);

    expect($event->speakers->contains($speaker))->toBeTrue()
        ->and($event->references->contains($reference))->toBeTrue();
});

it('updates an event with validate_only via admin-update-event without persisting', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Event Before Dry Run',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateEventTool::class, [
            'event_key' => $event->getRouteKey(),
            'title' => 'Should Not Persist',
            'validate_only' => true,
        ])
        ->assertOk();

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => 'Event Before Dry Run',
    ]);
});

it('batch-updates events and resolves speaker_keys via admin-batch-update-events', function () {
    $admin = adminMcpUser('super_admin');

    $eventA = adminMcpStableEvent([
        'title' => 'Batch Update Event Alpha',
    ]);
    $eventB = adminMcpStableEvent([
        'title' => 'Batch Update Event Beta',
    ]);
    $speaker = Speaker::factory()->create(['slug' => 'batch-update-speaker']);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchUpdateEventsTool::class, [
            'items' => [
                [
                    'event_key' => $eventA->getRouteKey(),
                    'title' => 'Updated Alpha',
                    'external_row_id' => 'row-alpha',
                    'speaker_keys' => [$speaker->slug],
                ],
                [
                    'event_key' => $eventB->getRouteKey(),
                    'title' => 'Updated Beta',
                    'external_row_id' => 'row-beta',
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.results.0.status', 'updated')
            ->where('data.results.1.status', 'updated')
            ->etc()
        );

    $this->assertDatabaseHas('events', ['id' => $eventA->id, 'title' => 'Updated Alpha']);
    $this->assertDatabaseHas('events', ['id' => $eventB->id, 'title' => 'Updated Beta']);
    expect($eventA->refresh()->speakers->contains($speaker))->toBeTrue();
});

it('batch-updates events detach or preserve speakers and references based on route-key array presence', function () {
    $admin = adminMcpUser('super_admin');

    $eventToDetach = adminMcpStableEvent([
        'title' => 'Batch Detach Relations Event',
    ]);
    $eventToPreserve = adminMcpStableEvent([
        'title' => 'Batch Preserve Relations Event',
    ]);

    $detachSpeaker = Speaker::factory()->create(['slug' => 'batch-detach-speaker']);
    $preserveSpeaker = Speaker::factory()->create(['slug' => 'batch-preserve-speaker']);
    $detachReference = Reference::factory()->create(['slug' => 'batch-detach-reference']);
    $preserveReference = Reference::factory()->create(['slug' => 'batch-preserve-reference']);

    $eventToDetach->keyPeople()->create([
        'speaker_id' => $detachSpeaker->id,
        'role' => 'speaker',
        'order_column' => 1,
    ]);
    $eventToPreserve->keyPeople()->create([
        'speaker_id' => $preserveSpeaker->id,
        'role' => 'speaker',
        'order_column' => 1,
    ]);
    $eventToDetach->references()->attach($detachReference->id);
    $eventToPreserve->references()->attach($preserveReference->id);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchUpdateEventsTool::class, [
            'items' => [
                [
                    'event_key' => $eventToDetach->getRouteKey(),
                    'speaker_keys' => [],
                    'reference_keys' => [],
                ],
                [
                    'event_key' => $eventToPreserve->getRouteKey(),
                    'title' => 'Batch Preserve Relations Event Updated',
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.results.0.status', 'updated')
            ->where('data.results.1.status', 'updated')
            ->etc()
        );

    $eventToDetach->refresh()->load(['speakers', 'references']);
    $eventToPreserve->refresh()->load(['speakers', 'references']);

    expect($eventToDetach->speakers)->toHaveCount(0)
        ->and($eventToDetach->references)->toHaveCount(0)
        ->and($eventToPreserve->speakers->contains($preserveSpeaker))->toBeTrue()
        ->and($eventToPreserve->references->contains($preserveReference))->toBeTrue();
});

it('batch-updates events with validate_only via admin-batch-update-events without persisting', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Batch Dry Run Event',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchUpdateEventsTool::class, [
            'items' => [
                [
                    'event_key' => $event->getRouteKey(),
                    'title' => 'Should Not Persist Batch',
                ],
            ],
            'validate_only' => true,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.validate_only', true)
            ->where('data.results.0.status', 'preview')
            ->etc()
        );

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => 'Batch Dry Run Event',
    ]);
});

it('batch-updates events returns unresolved_key for invalid event key via admin-batch-update-events', function () {
    $admin = adminMcpUser('super_admin');

    $event = adminMcpStableEvent([
        'title' => 'Valid Event For Batch Update',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminBatchUpdateEventsTool::class, [
            'items' => [
                [
                    'event_key' => $event->getRouteKey(),
                    'title' => 'Valid Update',
                ],
                [
                    'event_key' => '00000000-0000-0000-0000-000000000000',
                    'title' => 'Should Not Update',
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.results.0.status', 'updated')
            ->where('data.results.1.status', 'not_found')
            ->etc()
        );
});
