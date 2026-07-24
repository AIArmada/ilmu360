<?php

use AIArmada\CommerceSupport\Models\Role;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Enums\MemberSubjectType;
use App\Mcp\Prompts\MemberDocumentationToolRoutingPrompt;
use App\Mcp\Resources\Docs\MemberMcpGuideResource;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Member\MemberApproveContributionRequestTool;
use App\Mcp\Tools\Member\MemberCancelContributionRequestTool;
use App\Mcp\Tools\Member\MemberCancelMembershipApplicationTool;
use App\Mcp\Tools\Member\MemberCreateGitHubIssueTool;
use App\Mcp\Tools\Member\MemberDocumentationFetchTool;
use App\Mcp\Tools\Member\MemberDocumentationSearchTool;
use App\Mcp\Tools\Member\MemberGetRecordActionsTool;
use App\Mcp\Tools\Member\MemberGetRecordTool;
use App\Mcp\Tools\Member\MemberGetResourceMetaTool;
use App\Mcp\Tools\Member\MemberGetWriteSchemaTool;
use App\Mcp\Tools\Member\MemberListContributionRequestsTool;
use App\Mcp\Tools\Member\MemberListMembershipApplicationsTool;
use App\Mcp\Tools\Member\MemberListRecordsTool;
use App\Mcp\Tools\Member\MemberListResourcesTool;
use App\Mcp\Tools\Member\MemberRejectContributionRequestTool;
use App\Mcp\Tools\Member\MemberSearchEventsTool;
use App\Mcp\Tools\Member\MemberSubmitMembershipApplicationTool;
use App\Mcp\Tools\Member\MemberUpdateRecordTool;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\PassportUser;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Support\GitHub\GitHubIssueReportContract;
use App\Support\Mcp\McpTokenManager;
use App\Support\Mcp\MemberMcpDocumentationPreflight;
use App\Support\Search\PersonSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('lists accessible member resources for institution members through the MCP server', function () {
    [$member] = institutionMemberMcpContext();

    MemberServer::actingAs($member)
        ->tool(MemberListResourcesTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data.resources', 2)
            ->where('data.resources.0.key', 'institutions')
            ->where('data.resources.1.key', 'events')
            ->etc());
});

it('lists accessible member resources for person members through the MCP server', function () {
    [$member] = personMemberMcpContext();

    MemberServer::actingAs($member)
        ->tool(MemberListResourcesTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data.resources', 2)
            ->where('data.resources.0.key', 'people')
            ->where('data.resources.1.key', 'events')
            ->etc());
});

it('returns member resource metadata, record listings, and record detail for institutions', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin', status: 'pending');

    MemberServer::actingAs($member)
        ->tool(MemberGetResourceMetaTool::class, [
            'resource_key' => 'institutions',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'institutions')
            ->where('data.resource.write_support.update', true)
            ->where('data.resource.mcp_tools.get_record_actions.tool', 'member-get-record-actions')
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
            'resource_key' => 'institutions',
            'search' => $institution->name,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.0.id', $institution->getKey())
            ->where('data.0.title', $institution->name)
            ->where('meta.resource.key', 'institutions')
            ->where('meta.pagination.page', 1)
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberGetRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'institutions')
            ->where('data.record.route_key', $institution->getRouteKey())
            ->where('data.record.attributes.name', $institution->name)
            ->etc());
});

it('searches member persons by formatted public title parts through MCP list records', function () {
    [$member, $matchingPerson] = personMemberMcpContext(role: 'admin');

    $matchingPerson->update([
        'name' => 'Member MCP Person Match',
    ]);

    $otherPerson = Person::factory()->create([
        'name' => 'Member MCP Person Other',
        'status' => 'verified',
    ]);

    addTestMember($otherPerson, $member, 'viewer');

    $matchingPerson = $matchingPerson->fresh();

    expect($matchingPerson)->not->toBeNull();

    app(PersonSearchService::class)->syncPersonRecord($matchingPerson);
    app(PersonSearchService::class)->syncPersonRecord($otherPerson);

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
            'resource_key' => 'people',
            'search' => 'syeikhul maqari',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'people')
            ->where('meta.pagination.total', 1)
            ->where('data.0.id', (string) $matchingPerson->getKey())
            ->etc());
});

it('uses typo-tolerant institution search through member MCP list records', function () {
    [$member, $matchingInstitution] = institutionMemberMcpContext(role: 'admin');

    $matchingInstitution->update([
        'name' => 'Masjid Al Hidayah',
    ]);

    $otherInstitution = Institution::factory()->create([
        'name' => 'Pusat Pengajian An-Nur',
        'status' => 'verified',
    ]);

    addTestMember($otherInstitution, $member, 'viewer');

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
            'resource_key' => 'institutions',
            'search' => 'Hidayh',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('meta.resource.key', 'institutions')
            ->where('meta.pagination.total', 1)
            ->where('data.0.id', (string) $matchingInstitution->getKey())
            ->etc());
});

it('searches member references by descriptive public terms through MCP list records', function () {
    [$member, $matchingReference] = referenceMemberMcpContext(role: 'admin');

    $matchingReference->update([
        'title' => 'Rujukan Tajwid',
        'author' => 'Imam Contoh',
        'description' => 'Syarahan tajwid dan adab',
    ]);

    $otherReference = Reference::factory()->create([
        'title' => 'Rujukan Lain',
        'status' => 'verified',
    ]);

    addTestMember($otherReference, $member, 'viewer');

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
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

it('returns focused next-step actions for member records through the MCP server', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin', status: 'verified');

    MemberServer::actingAs($member)
        ->tool(MemberGetRecordActionsTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'institutions')
            ->where('data.record.route_key', $institution->getRouteKey())
            ->where('data.focus_actions.recommended_keys.0', 'get_update_schema')
            ->where('data.focus_actions.actions', fn ($actions): bool => array_diff(
                ['get_record', 'get_update_schema', 'update_record'],
                collect($actions)->pluck('key')->all(),
            ) === [])
            ->where('data.focus_actions.actions', fn ($actions): bool => data_get(collect($actions)->firstWhere('key', 'update_record'), 'tool') === 'member-update-record')
            ->where('data.focus_actions.actions', fn ($actions): bool => data_get(collect($actions)->firstWhere('key', 'update_record'), 'arguments.validate_only', false) === true)
            ->etc());
});

it('exposes timezone-aware metadata for member event resources', function () {
    [$member] = institutionMemberMcpContext();

    MemberServer::actingAs($member)
        ->tool(MemberGetResourceMetaTool::class, [
            'resource_key' => 'events',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.resource.timezone_sensitive', true)
            ->where('data.resource.date_semantics.local_date_filter', 'starts_on_local_date')
            ->where('data.resource.mcp_tools.list_records.arguments.starts_after', null)
            ->where('data.resource.mcp_tools.list_records.arguments.starts_before', null)
            ->where('data.resource.mcp_tools.list_records.arguments.starts_on_local_date', null)
            ->where('data.resource.mcp_tools.list_records.tool', 'member-list-records')
            ->where('data.resource.mcp_tools.get_record_actions.tool', 'member-get-record-actions')
            ->where('data.resource.mcp_tools.list_records.arguments.resource_key', 'events')
            ->etc());
});

it('filters member event records by local date through the MCP server', function () {
    [$member, $institution] = institutionMemberMcpContext();

    $member->forceFill([
        'timezone' => 'Asia/Kuala_Lumpur',
    ])->save();

    $matchingEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Date Match',
        'starts_at' => Carbon::parse('2026-05-01 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Date Miss',
        'starts_at' => Carbon::parse('2026-05-02 02:00:00', 'UTC'),
        'status' => 'approved',
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
            'resource_key' => 'events',
            'starts_on_local_date' => '2026-05-01',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('data', 1)
            ->where('data.0.id', $matchingEvent->getKey())
            ->where('data.0.title', 'Member MCP Date Match')
            ->where('meta.resource.key', 'events')
            ->where('meta.search', null)
            ->etc());
});

it('lists related events for institution members through the member MCP server', function () {
    [$member, $institution] = institutionMemberMcpContext();

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Institution Event',
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberListRecordsTool::class, [
            'resource_key' => 'events',
            'search' => $event->title,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.0.id', $event->getKey())
            ->where('data.0.title', $event->title)
            ->where('meta.resource.key', 'events')
            ->etc());
});

it('searches /majlis-style events through the dedicated member MCP tool', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    addTestMember($institution, $member, 'viewer');

    $matchingEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Majlis Search Match',
        'status' => 'approved',
        'is_muslim_only' => true,
        'starts_at' => Carbon::parse('2026-05-26 12:00:00', 'UTC'),
    ]);

    Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Majlis Search Non Match',
        'status' => 'approved',
        'is_muslim_only' => false,
        'starts_at' => Carbon::parse('2026-05-26 12:00:00', 'UTC'),
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberSearchEventsTool::class, [
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

it('surfaces public event change projections on member event record detail through the MCP server', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin');

    $actor = User::factory()->create();
    $original = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Change Surface Original',
        'slug' => 'member-mcp-change-surface-original',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => Carbon::parse('2026-05-01 00:00:00', 'UTC'),
    ]);
    $firstReplacement = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Change Surface First Replacement',
        'slug' => 'member-mcp-change-surface-first-replacement',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => Carbon::parse('2026-05-01 00:00:00', 'UTC'),
    ]);
    $finalReplacement = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Member MCP Change Surface Final Replacement',
        'slug' => 'member-mcp-change-surface-final-replacement',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => Carbon::parse('2026-05-01 00:00:00', 'UTC'),
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
        'visibility' => 'private',
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberGetRecordTool::class, [
            'resource_key' => 'events',
            'record_key' => $original->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
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

it('denies non-member users from member MCP tools', function () {
    $user = User::factory()->create();

    MemberServer::actingAs($user)
        ->tool(MemberListResourcesTool::class)
        ->assertHasErrors(['Forbidden.']);
});

it('denies non-member users from member event search tool', function () {
    $user = User::factory()->create();

    MemberServer::actingAs($user)
        ->tool(MemberSearchEventsTool::class, [
            'time_scope' => 'all',
            'per_page' => 5,
        ])
        ->assertHasErrors(['Forbidden.']);
});

it('hides member write tools when the authenticated member has no writable access', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'viewer');

    MemberServer::actingAs($member)
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
        ])
        ->assertHasErrors(['Tool [member-get-write-schema] not found.']);
});

it('returns member update schema and updates institutions through member MCP write tools', function () {
    ensureMemberMcpMalaysiaCountryExists();

    [$member, $institution] = institutionMemberMcpContext(role: 'admin');
    $originalAddress = $institution->fresh()?->primaryAddress();
    $originalLat = $originalAddress?->lat;
    $originalLng = $originalAddress?->lng;

    MemberServer::actingAs($member)
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'institutions')
            ->where('data.schema.resource_key', 'institutions')
            ->where('data.schema.operation', 'update')
            ->where('data.schema.transport', 'mcp')
            ->where('data.schema.tool', 'member-update-record')
            ->where('data.schema.tool_arguments.resource_key', 'institutions')
            ->where('data.schema.tool_arguments.record_key', $institution->getRouteKey())
            ->where('data.schema.tool_arguments.payload', 'object')
            ->where('data.schema.tool_arguments.validate_only', false)
            ->where('data.schema.endpoint', null)
            ->where('data.schema.content_type', 'application/json')
            ->where('data.schema.media_uploads_supported', true)
            ->where('data.schema.media_upload_transport', 'json_base64_descriptor_or_download_url')
            ->where('data.schema.unsupported_fields', [])
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');
                $contactItemFields = collect(data_get($fieldMap->get('contactMethods'), 'item_schema.fields', []))->keyBy('name');

                return data_get($fieldMap->get('logo'), 'mcp_upload.shape') === 'file_descriptor'
                    && data_get($fieldMap->get('gallery'), 'mcp_upload.shape') === 'array<file_descriptor>'
                    && data_get($fieldMap->get('address'), 'required') === false
                    && data_get($fieldMap->get('nickname'), 'normalization.empty_string_at_mutation_layer') === 'null'
                    && data_get($fieldMap->get('contactMethods'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && $contactItemFields->has('type')
                    && $contactItemFields->has('value')
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false;
            })
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
            'payload' => [
                'name' => 'Member MCP Institution Updated',
                'nickname' => 'Member MCP Masjid',
                'type' => 'masjid',
                'status' => 'pending',
                'allow_public_event_submission' => true,
                'slug' => 'attempted-member-institution-injection',
                'cover' => memberMcpImageDescriptor('member-mcp-cover.png'),
                'gallery' => [
                    memberMcpImageDescriptor('member-mcp-gallery.png'),
                ],
                'address' => [
                    'country_id' => ensureMemberMcpMalaysiaCountryExists(),
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.record.attributes.name', 'Member MCP Institution Updated')
            ->where('data.record.attributes.nickname', 'Member MCP Masjid')
            ->etc());

    expect($institution->fresh()?->name)->toBe('Member MCP Institution Updated')
        ->and($institution->fresh()?->nickname)->toBe('Member MCP Masjid')
        ->and($institution->fresh()?->slug)->not->toBe('attempted-member-institution-injection')
        ->and($institution->fresh()?->getMedia('cover'))->toHaveCount(1)
        ->and($institution->fresh()?->getMedia('gallery'))->toHaveCount(1)
        ->and(abs(((float) $institution->fresh()?->primaryAddress()?->lat) - (float) $originalLat))->toBeLessThan(0.000001)
        ->and(abs(((float) $institution->fresh()?->primaryAddress()?->lng) - (float) $originalLng))->toBeLessThan(0.000001);

    MemberServer::actingAs($member)
        ->tool(MemberUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
            'payload' => [
                'clear_gallery' => true,
            ],
        ])
        ->assertHasErrors(['Destructive media clear flags are not supported through MCP. Upload a replacement file or array when the schema advertises that media field.']);
});

it('returns member update schema for persons with surfaced mutation semantics', function () {
    [$member, $person] = personMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'people',
            'record_key' => $person->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'people')
            ->where('data.schema.resource_key', 'people')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('avatar'), 'mcp_upload.shape') === 'file_descriptor'
                    && data_get($fieldMap->get('gallery'), 'mcp_upload.shape') === 'array<file_descriptor>'
                    && data_get($fieldMap->get('address'), 'required') === false
                    && data_get($fieldMap->get('address'), 'clear_semantics.empty_object') === 'invalid_without_country'
                    && data_get($fieldMap->get('address.country_id'), 'required_when_parent_present_on_update') === true
                    && data_get($fieldMap->get('language_ids'), 'collection_semantics.submitted_array') === 'replace_relation_sync'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false
                    && $qualificationItemFields->has('institution')
                    && $qualificationItemFields->has('degree');
            })
            ->etc());
});

it('requires an explicit person country when the address is mutated through member MCP write tools', function () {
    [$member, $person] = personMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberUpdateRecordTool::class, [
            'resource_key' => 'people',
            'record_key' => $person->getKey(),
            'payload' => [
                'name' => $person->name,
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

it('returns member update schema for references with surfaced mutation semantics', function () {
    [$member, $reference] = referenceMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'references',
            'record_key' => $reference->getRouteKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'references')
            ->where('data.schema.resource_key', 'references')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');

                return data_get($fieldMap->get('front_cover'), 'mcp_upload.shape') === 'file_descriptor'
                    && data_get($fieldMap->get('gallery'), 'mcp_upload.shape') === 'array<file_descriptor>'
                    && data_get($fieldMap->get('author'), 'clear_semantics.explicit_null') === 'clear_to_null'
                    && data_get($fieldMap->get('publication_year'), 'normalization.empty_string_at_mutation_layer') === 'null'
                    && data_get($fieldMap->get('social_media'), 'collection_semantics.submitted_array') === 'replace_collection'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.normalizes_to') === 'x'
                    && data_get($fieldMap->get('social_media'), 'input_normalization.platform_aliases.x.accepted_by_write_validation') === false;
            })
            ->etc());
});

it('returns member update schema for events with surfaced mutation semantics', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin');

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'events',
            'record_key' => $event->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'events')
            ->where('data.schema.resource_key', 'events')
            ->where('data.schema.fields', function ($fields): bool {
                $fieldMap = collect($fields)->keyBy('name');
                $otherKeyPeopleFields = collect(data_get($fieldMap->get('other_key_people'), 'item_schema.fields', []))->keyBy('name');

                return data_get($fieldMap->get('title'), 'required') === false
                    && data_get($fieldMap->get('references'), 'collection_semantics.explicit_null') === 'clear_collection'
                    && data_get($fieldMap->get('persons'), 'collection_semantics.submitted_array') === 'replace_person_subset_and_rebuild_key_people'
                    && data_get($fieldMap->get('primary_organizer_id'), 'accepted_models') === [Institution::class, Person::class]
                    && data_get($fieldMap->get('registration_mode'), 'lock_behavior.when_event_has_registrations') === 'retain_current_value'
                    && $otherKeyPeopleFields->has('role_code')
                    && $otherKeyPeopleFields->has('display_name');
            })
            ->etc());
});

it('registers member write tools when the MCP actor is a normalized Passport user', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs(memberPassportUser($member))
        ->tool(MemberGetWriteSchemaTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
        ])
        ->assertOk()
        ->assertHasNoErrors();
});

it('previews member institution updates without persisting the record', function () {
    ensureMemberMcpMalaysiaCountryExists();

    [$member, $institution] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $institution->getKey(),
            'validate_only' => true,
            'payload' => [
                'name' => 'Previewed Member MCP Institution',
                'nickname' => 'Previewed Masjid',
                'type' => 'masjid',
                'status' => 'pending',
                'allow_public_event_submission' => true,
                'address' => [
                    'country_id' => ensureMemberMcpMalaysiaCountryExists(),
                ],
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.resource.key', 'institutions')
            ->where('data.preview.validate_only', true)
            ->where('data.preview.operation', 'update')
            ->where('data.preview.current_record.route_key', $institution->getRouteKey())
            ->where('data.preview.normalized_payload.name', 'Previewed Member MCP Institution')
            ->etc());

    expect($institution->fresh()?->name)->not->toBe('Previewed Member MCP Institution');
});

it('lists and processes contribution requests through member MCP workflow tools', function () {
    [$member, $institution] = institutionMemberMcpContext(role: 'admin');

    $ownRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'proposer_id' => $member->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Own pending request.',
        ],
        'original_data' => [
            'description' => (string) $institution->description,
        ],
    ]);

    $reviewRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Approved through member MCP.',
        ],
        'original_data' => [
            'description' => (string) $institution->description,
        ],
    ]);

    $rejectRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Rejected through member MCP.',
        ],
        'original_data' => [
            'description' => (string) $institution->description,
        ],
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberListContributionRequestsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.my_requests', fn ($requests): bool => collect($requests)->pluck('id')->contains($ownRequest->getKey()))
            ->where('data.pending_approvals', fn ($requests): bool => collect($requests)->pluck('id')->contains($reviewRequest->getKey())
                && collect($requests)->pluck('id')->contains($rejectRequest->getKey()))
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberApproveContributionRequestTool::class, [
            'request_id' => $reviewRequest->getKey(),
            'reviewer_note' => 'Approved through member MCP.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.request.id', $reviewRequest->getKey())
            ->where('data.request.status', 'approved')
            ->where('data.request.reviewer_note', 'Approved through member MCP.')
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberRejectContributionRequestTool::class, [
            'request_id' => $rejectRequest->getKey(),
            'reason_code' => 'needs_more_evidence',
            'reviewer_note' => 'Need stronger evidence.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.request.id', $rejectRequest->getKey())
            ->where('data.request.status', 'rejected')
            ->where('data.request.reason_code', 'needs_more_evidence')
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberCancelContributionRequestTool::class, [
            'request_id' => $ownRequest->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.request.id', $ownRequest->getKey())
            ->where('data.request.status', 'cancelled')
            ->etc());

    expect($reviewRequest->fresh()?->status)->toBe(ContributionRequestStatus::Approved)
        ->and($rejectRequest->fresh()?->status)->toBe(ContributionRequestStatus::Rejected)
        ->and($ownRequest->fresh()?->status)->toBe(ContributionRequestStatus::Cancelled)
        ->and($institution->fresh()?->description)->toBe('Approved through member MCP.');
});

it('lists submits and cancels membership applications through member MCP workflow tools', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');

    $listedInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $applicationTarget = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $listedApplication = MembershipApplication::factory()
        ->for($listedInstitution, 'subject')
        ->create([
            'applicant_id' => $member->getKey(),
            'status' => 'pending',
        ]);

    MemberServer::actingAs($member)
        ->tool(MemberListMembershipApplicationsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data', fn ($applications): bool => collect($applications)->pluck('id')->contains($listedApplication->getKey()))
            ->etc());

    MemberServer::actingAs($member)
        ->tool(MemberSubmitMembershipApplicationTool::class, [
            'subject_type' => MemberSubjectType::Institution->value,
            'subject' => $applicationTarget->getKey(),
            'justification' => 'I help manage this institution.',
            'evidence' => [
                memberMcpImageDescriptor('member-mcp-application-evidence.png'),
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.application.status', 'pending')
            ->etc());

    $application = MembershipApplication::query()
        ->where('applicant_id', $member->getKey())
        ->where('subject_id', $applicationTarget->getKey())
        ->latest('created_at')
        ->firstOrFail();

    expect($application->getMedia('evidence'))->toHaveCount(1);

    MemberServer::actingAs($member)
        ->tool(MemberCancelMembershipApplicationTool::class, [
            'application_id' => $application->getKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.application.id', $application->getKey())
            ->where('data.application.status', 'cancelled')
            ->etc());

    expect($application->fresh()?->status->value)->toBe('cancelled');
});

it('creates plain github issues through the member MCP tool', function () {
    configureGithubIssueReportingForMemberMcp();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::response([
            'number' => 654,
            'title' => '[Bug] Member MCP GitHub issue',
            'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/654',
            'html_url' => 'https://github.com/AIArmada/ilmu360/issues/654',
        ], 201),
    ]);

    [$member] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberCreateGitHubIssueTool::class, [
            'category' => 'bug',
            'title' => 'Member MCP GitHub issue',
            'summary' => 'The member MCP GitHub issue tool should create a plain issue without Copilot assignment.',
            'platform' => 'chatgpt',
            'client_name' => 'ChatGPT',
            'client_version' => 'GPT-5.4',
            'tool_name' => 'member-create-github-issue',
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

it('hides the member github issue tool when github issue reporting is disabled', function () {
    configureGithubIssueReportingForMemberMcp(['enabled' => false]);

    [$member] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberCreateGitHubIssueTool::class, [
            'category' => 'bug',
            'title' => 'Hidden tool',
            'summary' => 'This should not be callable when disabled.',
            'platform' => 'chatgpt',
        ])
        ->assertHasErrors(['Tool [member-create-github-issue] not found.']);
});

it('keeps admin and member MCP boundaries separate', function () {
    $admin = globalRoleMcpUser('super_admin');

    MemberServer::actingAs($admin)
        ->tool(MemberListResourcesTool::class)
        ->assertHasErrors(['Forbidden.']);
});

it('serves an authenticated event stream compatibility endpoint for /mcp/member', function () {
    [$member] = institutionMemberMcpContext();
    $token = $member->createToken('mcp-member-http-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $response = $this->withToken($token)
        ->get('/mcp/member');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/event-stream');
    expect($response->streamedContent())->toContain(': keep-alive');
});

it('returns a bearer-auth challenge for unauthenticated member MCP stream requests', function () {
    $response = $this->withHeaders([
        'Accept' => 'text/event-stream',
    ])->get('/mcp/member');

    $response->assertUnauthorized();
    $response->assertHeader('WWW-Authenticate');
    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="mcp"');
});

it('serves the member MCP stream for Passport-authenticated eligible users', function () {
    [$member] = institutionMemberMcpContext();

    Passport::actingAs(memberPassportUser($member), ['mcp:use']);

    $response = $this->get('/mcp/member');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/event-stream');
    expect($response->streamedContent())->toContain(': keep-alive');
});

it('initializes and lists member MCP tools over the HTTP endpoint for Passport-authenticated members', function () {
    configureGithubIssueReportingForMemberMcp();

    [$member] = institutionMemberMcpContext(role: 'admin');

    Passport::actingAs(memberPassportUser($member), ['mcp:use']);

    $initialize = $this->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-member-mcp-passport',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'list-tools-member-mcp-passport',
        'method' => 'tools/list',
        'params' => [
            'per_page' => 50,
        ],
    ])->assertOk();

    $tools = collect($listTools->json('result.tools'))->keyBy('name');

    expect($tools->keys()->all())->toContain(
        'search',
        'fetch',
        'member-list-resources',
        'member-get-resource-meta',
        'member-search-events',
        'member-list-records',
        'member-list-related-records',
        'member-get-record',
        'member-get-record-actions',
        'member-upload-event-cover-image',
        'member-upload-event-poster-image',
        'member-get-write-schema',
        'member-list-contribution-requests',
        'member-approve-contribution-request',
        'member-reject-contribution-request',
        'member-cancel-contribution-request',
        'member-list-membership-applications',
        'member-submit-membership-application',
        'member-cancel-membership-application',
        'member-create-github-issue',
        'member-update-record',
    );

    expect($tools->keys()->all())->not->toContain('member-generate-event-cover-image', 'member-generate-event-poster-image');

    expect($tools->keys()->all())->not->toContain('admin-list-resources', 'admin-list-records', 'member-read-debug-log');

    expect($tools->keys()->all())->not->toContain(
        'member-delete-record',
        'member-delete',
        'member-remove-record',
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

    expect($tools->get('member-list-resources')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
    ]);

    $readOnlyTools = $tools->filter(fn (array $tool): bool => data_get($tool, 'annotations.readOnlyHint') === true);

    expect($readOnlyTools->isNotEmpty())->toBeTrue()
        ->and($readOnlyTools->every(fn (array $tool): bool => data_get($tool, 'annotations.destructiveHint') === false))->toBeTrue()
        ->and($readOnlyTools->every(fn (array $tool): bool => data_get($tool, 'annotations.openWorldHint') === false))->toBeTrue();

    expect($tools->get('member-list-related-records')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('member-get-write-schema')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('member-update-record')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ]);

    expect($tools->get('member-create-github-issue')['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => true,
    ]);

    expect(data_get($tools->get('member-submit-membership-application'), 'inputSchema.properties.evidence.type'))->toBe('array');
    expect(data_get($tools->get('member-submit-membership-application'), 'inputSchema.properties.evidence.items.type'))->toBe('object');
    expect(data_get($tools->get('member-submit-membership-application'), 'inputSchema.properties.evidence.items.required'))
        ->toBe(['filename']);
    expect(data_get($tools->get('member-submit-membership-application'), 'inputSchema.properties.evidence.items.properties.content_base64.type'))
        ->toBe('string');
    expect(data_get($tools->get('member-submit-membership-application'), 'inputSchema.properties.evidence.items.properties.content_url.type'))
        ->toBe('string');

    $githubIssueCategorySchema = data_get($tools->get('member-create-github-issue'), 'inputSchema.properties.category');

    expect($githubIssueCategorySchema['enum'] ?? null)->toBe(GitHubIssueReportContract::categories())
        ->and($githubIssueCategorySchema['default'] ?? null)->toBe(GitHubIssueReportContract::DEFAULT_CATEGORY)
        ->and((string) ($githubIssueCategorySchema['description'] ?? ''))
        ->toContain('bug', 'docs_mismatch', 'proposal', 'feature_request', 'parameter_change', 'other');
});

it('returns forbidden for Passport-authenticated users without member access on the member MCP stream endpoint', function () {
    $user = User::factory()->create();

    Passport::actingAs(memberPassportUser($user), ['mcp:use']);

    $this->get('/mcp/member')->assertForbidden();
});

it('returns forbidden for authenticated users without member access on the member MCP stream endpoint', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp-member-http-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $this->withToken($token)
        ->get('/mcp/member')
        ->assertForbidden();
});

it('rejects admin-scoped tokens on the member MCP stream endpoint even for dual-scope users', function () {
    $member = globalRoleMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    addTestMember($institution, $member, 'admin');

    $token = $member->createToken('mcp-admin-only', [McpTokenManager::ADMIN_ABILITY])->plainTextToken;

    $this->withToken($token)
        ->get('/mcp/member')
        ->assertForbidden();
});

it('rejects legacy wildcard MCP tokens on the member MCP stream endpoint', function () {
    $member = globalRoleMcpUser('super_admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    addTestMember($institution, $member, 'admin');

    $token = $member->createToken('legacy-admin-mcp')->plainTextToken;

    $this->withToken($token)
        ->get('/mcp/member')
        ->assertForbidden();
});

it('initializes and lists member MCP tools over the HTTP endpoint', function () {
    configureGithubIssueReportingForMemberMcp();

    [$member] = personMemberMcpContext(role: 'admin');
    $token = $member->createToken('mcp-member-http-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-member-mcp',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'list-tools-member-mcp',
        'method' => 'tools/list',
        'params' => [
            'per_page' => 50,
        ],
    ])->assertOk();

    $toolNames = collect($listTools->json('result.tools'))->pluck('name')->all();

    expect($toolNames)->toContain(
        'search',
        'fetch',
        'member-list-resources',
        'member-get-resource-meta',
        'member-search-events',
        'member-list-records',
        'member-list-related-records',
        'member-get-record',
        'member-get-record-actions',
        'member-upload-event-cover-image',
        'member-upload-event-poster-image',
        'member-get-write-schema',
        'member-list-contribution-requests',
        'member-approve-contribution-request',
        'member-reject-contribution-request',
        'member-cancel-contribution-request',
        'member-list-membership-applications',
        'member-submit-membership-application',
        'member-cancel-membership-application',
        'member-create-github-issue',
        'member-update-record',
    );

    expect($toolNames)->not->toContain('member-generate-event-cover-image', 'member-generate-event-poster-image');

    expect($toolNames)->not->toContain(
        'member-delete-record',
        'member-delete',
        'member-remove-record',
        'member-read-debug-log',
    );

    $unavailableDebugLogCall = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'call-member-read-debug-log',
        'method' => 'tools/call',
        'params' => [
            'name' => 'member-read-debug-log',
            'arguments' => ['filter' => 'mcp.image_upload'],
        ],
    ])->assertOk();

    expect($unavailableDebugLogCall->json('error.message'))->toBe('Tool [member-read-debug-log] not found.');
});

it('searches and fetches verified documentation through member MCP tools', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationSearchTool::class, [
            'query' => 'member write capable resources',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-member-mcp-guide'
                    && ($result['title'] ?? null) === 'ilmu360° Member MCP Agent Guide'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_member_agent_guide.md'
            ))
            ->etc())
        ->assertSee([
            'docs-member-mcp-guide',
            'ilmu360° Member MCP Agent Guide',
        ]);

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationSearchTool::class, [
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

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationFetchTool::class, [
            'id' => 'docs-member-mcp-guide',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-member-mcp-guide')
            ->where('title', 'ilmu360° Member MCP Agent Guide')
            ->where('url', 'file://docs/ilmu360_mcp_member_agent_guide.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_mcp_member_agent_guide.md')
            ->where('metadata.relative_path', 'docs/ilmu360_mcp_member_agent_guide.md')
            ->where('text', fn (string $text): bool => str_contains($text, 'Current member-write-capable resources include:'))
            ->etc())
        ->assertSee([
            'docs-member-mcp-guide',
            '# ilmu360° Member MCP Agent Guide',
            'Current member-write-capable resources include:',
        ]);

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationFetchTool::class, [
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

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationSearchTool::class, [
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

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationFetchTool::class, [
            'id' => 'docs-mcp-tool-examples',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-mcp-tool-examples')
            ->where('title', 'ilmu360° MCP Tool Examples')
            ->where('url', 'file://docs/ilmu360_mcp_tool_examples.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_mcp_tool_examples.md')
            ->where('metadata.relative_path', 'docs/ilmu360_mcp_tool_examples.md')
            ->where('text', fn (string $text): bool => str_contains($text, '### Discover resources'))
            ->etc())
        ->assertSee([
            'docs-mcp-tool-examples',
            '# ilmu360° MCP Tool Examples',
            '### Discover resources',
        ]);
});

it('auto-injects guide on first member operational call and allows the retry', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');
    $token = $member->createToken('mcp-member-preflight-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-member-mcp-preflight',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'call-member-list-records-first-attempt',
        'method' => 'tools/call',
        'params' => [
            'name' => 'member-list-records',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($firstCall->json('result.isError'))->toBeFalse();
    expect($firstCall->json('result.structuredContent.action'))->toBe('documentation_preflight_injected');
    expect($firstCall->json('result.structuredContent.document.id'))->toBe(MemberMcpDocumentationPreflight::GUIDE_DOCUMENT_ID);
    expect($firstCall->json('result.structuredContent.retry.tool_name'))->toBe('member-list-records');
    expect($firstCall->json('result.content.0.text'))->toContain('Guide auto-loaded');

    $allowedCall = $this->withToken($token)->withHeaders([
        'MCP-Session-Id' => (string) $sessionId,
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'call-member-list-resources-after-docs',
        'method' => 'tools/call',
        'params' => [
            'name' => 'member-list-resources',
            'arguments' => [],
        ],
    ])->assertOk();

    expect($allowedCall->json('result.isError'))->toBeFalse();
    expect($allowedCall->json('result.structuredContent.data.resources'))->toBeArray();
});

it('lists and reads the documentation routing prompt through the member MCP server', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->prompt(MemberDocumentationToolRoutingPrompt::class, [
            'topic' => 'media uploads',
        ])
        ->assertOk()
        ->assertName('documentation-tool-routing')
        ->assertTitle('Documentation Tool Routing')
        ->assertSee([
            'Use the verified documentation tools like this:',
            'Before the first ilmu360 member MCP operational tool call for runtime reads, search, lookup, listing, metadata inspection, relation traversal, write-schema discovery, preview, write, or workflow actions, ensure the verified guide is already in context.',
            'Use `fetch` first',
            'Search `institutions` first when the noun matches an institution type',
            'Topic-specific guidance for "media uploads":',
            'Fetch `docs-member-mcp-guide` and focus on the MCP media/file upload contract and preview rules sections.',
        ]);

    $token = $member->createToken('mcp-member-prompt-list-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-member-mcp-prompts',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'list-member-mcp-prompts',
        'method' => 'prompts/list',
        'params' => [],
    ])->assertOk();

    $prompts = collect($listPrompts->json('result.prompts'))->keyBy('name');

    expect($prompts->keys()->all())->toContain('documentation-tool-routing');
    expect($prompts->get('documentation-tool-routing'))->toMatchArray([
        'title' => 'Documentation Tool Routing',
        'description' => 'Short guidance for deciding when to use the verified member documentation search and fetch tools exposed by this server, with an optional topic hint for more targeted advice.',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'get-member-mcp-prompt',
        'method' => 'prompts/get',
        'params' => [
            'name' => 'documentation-tool-routing',
            'arguments' => [
                'topic' => 'media uploads',
            ],
        ],
    ])->assertOk();

    expect($getPrompt->json('result.description'))->toBe('Short guidance for deciding when to use the verified member documentation search and fetch tools exposed by this server, with an optional topic hint for more targeted advice.');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Before the first ilmu360 member MCP operational tool call for runtime reads, search, lookup, listing, metadata inspection, relation traversal, write-schema discovery, preview, write, or workflow actions, ensure the verified guide is already in context.');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('a fresh docs fetch may be skipped only when the fetched guide is already active in context');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Use `fetch` first');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Search `institutions` first when the noun matches an institution type');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Topic-specific guidance for "media uploads":');
    expect($getPrompt->json('result.messages.0.content.text'))->toContain('Fetch `docs-member-mcp-guide` and focus on the MCP media/file upload contract and preview rules sections.');

    MemberServer::actingAs($member)
        ->completion(MemberDocumentationToolRoutingPrompt::class, 'topic', 'ru')
        ->assertOk()
        ->assertCompletionValues(['runtime records']);
});

it('lists and reads verified documentation resources through the member MCP server', function () {
    [$member] = institutionMemberMcpContext(role: 'admin');

    MemberServer::actingAs($member)
        ->resource(MemberMcpGuideResource::class)
        ->assertOk()
        ->assertName('docs-member-mcp-guide')
        ->assertTitle('ilmu360° Member MCP Agent Guide')
        ->assertSee([
            '# ilmu360° Member MCP Agent Guide',
            'Verified Documentation Resource',
            '## MCP capability matrix',
            '## Writable resource matrix',
            '## Entity selection heuristics for record search',
            '## Quick search playbook',
            'Current member-write-capable resources include:',
            'member-list-related-records',
            'member-update-record',
        ]);

    $token = $member->createToken('mcp-member-resource-list-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken;

    $initialize = $this->withToken($token)->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'initialize-member-mcp-resources',
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
    ])->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'list-member-mcp-resources',
        'method' => 'resources/list',
        'params' => [],
    ])->assertOk();

    $resources = collect($listResources->json('result.resources'))->keyBy('name');

    expect($resources->keys()->all())->toContain('docs-member-mcp-guide');
    expect($resources->keys()->all())->not->toContain('docs-crud-capability-matrix');
    expect($resources->get('docs-member-mcp-guide'))->toMatchArray([
        'uri' => 'file://docs/ilmu360_mcp_member_agent_guide.md',
        'mimeType' => 'text/markdown',
    ]);
});

/**
 * @return array{0: User, 1: Institution}
 */
function institutionMemberMcpContext(string $role = 'viewer', string $status = 'verified'): array
{
    $institution = Institution::factory()->create([
        'status' => $status,
    ]);
    $member = User::factory()->create();

    addTestMember($institution, $member, $role);

    return [$member, $institution];
}

function memberPassportUser(User $user): PassportUser
{
    return PassportUser::query()->findOrFail($user->getKey());
}

/**
 * @return array{0: User, 1: Person}
 */
function personMemberMcpContext(string $role = 'viewer', string $status = 'verified'): array
{
    $person = Person::factory()->create([
        'status' => $status,
    ]);
    $member = User::factory()->create();

    addTestMember($person, $member, $role);

    return [$member, $person];
}

/**
 * @return array{0: User, 1: Reference}
 */
function referenceMemberMcpContext(string $role = 'viewer', string $status = 'verified'): array
{
    $reference = Reference::factory()->create([
        'status' => $status,
    ]);
    $member = User::factory()->create();

    addTestMember($reference, $member, $role);

    return [$member, $reference];
}

function globalRoleMcpUser(string $role): User
{
    $roleRecord = Role::query()->where('name', $role)->where('guard_name', 'web')->first();

    if (! $roleRecord instanceof Role) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();

    $modelHasRolesTable = (string) (config('permission.table_names.model_has_roles') ?? 'model_has_roles');
    $rolePivotKey = (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
    $modelMorphKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');

    $assignment = [
        $rolePivotKey => $roleRecord->getKey(),
        $modelMorphKey => (string) $user->getKey(),
        'model_type' => $user->getMorphClass(),
    ];

    if (config('permission.teams')) {
        $teamForeignKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');
        $assignment[$teamForeignKey] = null;
    }

    DB::table($modelHasRolesTable)->insert($assignment);

    return $user;
}

/**
 * @return array{filename: string, mime_type: string, content_base64: string}
 */
function memberMcpImageDescriptor(string $name): array
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

function ensureMemberMcpMalaysiaCountryExists(): string
{
    return (string) ensureTestMalaysiaCountry()->getKey();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function configureGithubIssueReportingForMemberMcp(array $overrides = []): void
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
