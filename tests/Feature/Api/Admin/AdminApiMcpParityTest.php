<?php

use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventFormat;
use App\Enums\EventVisibility;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Admin\AdminCreateRecordTool;
use App\Mcp\Tools\Admin\AdminGetContributionRequestReviewSchemaTool;
use App\Mcp\Tools\Admin\AdminGetEventModerationSchemaTool;
use App\Mcp\Tools\Admin\AdminGetMembershipClaimReviewSchemaTool;
use App\Mcp\Tools\Admin\AdminGetReportTriageSchemaTool;
use App\Mcp\Tools\Admin\AdminListRecordsTool;
use App\Mcp\Tools\Admin\AdminListRelatedRecordsTool;
use App\Mcp\Tools\Admin\AdminModerateEventTool;
use App\Mcp\Tools\Admin\AdminReviewContributionRequestTool;
use App\Mcp\Tools\Admin\AdminReviewMembershipClaimTool;
use App\Mcp\Tools\Admin\AdminTriageReportTool;
use App\Mcp\Tools\Admin\AdminUpdateRecordTool;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\ModerationReview;
use App\Models\Report;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('keeps admin api and admin mcp speaker search results aligned', function () {
    $admin = parityAdminUser('super_admin');
    $matchingSpeaker = Speaker::factory()->create([
        'name' => 'Admin Parity Speaker Match',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
        'is_active' => true,
    ]);
    $otherSpeaker = Speaker::factory()->create([
        'name' => 'Admin Parity Speaker Other',
        'status' => 'verified',
        'is_active' => true,
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($matchingSpeaker);
    app(SpeakerSearchService::class)->syncSpeakerRecord($otherSpeaker);

    Sanctum::actingAs($admin);

    $apiResponse = $this->getJson('/api/v1/admin/speakers?search='.urlencode('syeikhul maqari'))
        ->assertOk();

    $mcpResponse = AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'speakers',
            'search' => 'syeikhul maqari',
        ])
        ->assertOk();

    expect(collect($apiResponse->json('data'))->pluck('route_key')->all())
        ->toEqual(collect(adminMcpStructuredContent($mcpResponse)['data'] ?? [])->pluck('route_key')->all())
        ->toContain((string) $matchingSpeaker->getRouteKey());
});

it('keeps admin api and admin mcp event filter results aligned', function () {
    $admin = parityAdminUser('super_admin');
    $matchingEvent = Event::factory()->create([
        'title' => 'Admin Parity Matching Event',
        'status' => 'approved',
        'event_format' => EventFormat::Online,
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    Event::factory()->create([
        'title' => 'Admin Parity Wrong Format Event',
        'status' => 'approved',
        'event_format' => EventFormat::Physical,
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    Event::factory()->create([
        'title' => 'Admin Parity Wrong Status Event',
        'status' => 'draft',
        'event_format' => EventFormat::Online,
        'visibility' => EventVisibility::Public,
        'is_active' => true,
    ]);

    Sanctum::actingAs($admin);

    $apiResponse = $this->getJson('/api/v1/admin/events?filter[status]=approved&filter[event_format]=online&filter[visibility]=public')
        ->assertOk();

    $mcpResponse = AdminServer::actingAs($admin)
        ->tool(AdminListRecordsTool::class, [
            'resource_key' => 'events',
            'filters' => [
                'status' => 'approved',
                'event_format' => 'online',
                'visibility' => 'public',
            ],
        ])
        ->assertOk();

    expect(collect($apiResponse->json('data'))->pluck('route_key')->all())
        ->toEqual(collect(adminMcpStructuredContent($mcpResponse)['data'] ?? [])->pluck('route_key')->all())
        ->toContain((string) $matchingEvent->getRouteKey());
});

it('keeps admin api and admin mcp related record listings aligned', function () {
    $admin = parityAdminUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Admin Parity Nested Speaker',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $matchingEvent = Event::factory()->create([
        'title' => 'Admin Parity Nested Event '.Str::ulid(),
    ]);

    $matchingEvent->speakers()->attach($speaker);

    Sanctum::actingAs($admin);

    $apiResponse = $this->getJson('/api/v1/admin/speakers/'.$speaker->getRouteKey().'/relations/events?search='.urlencode($matchingEvent->title))
        ->assertOk();

    $mcpResponse = AdminServer::actingAs($admin)
        ->tool(AdminListRelatedRecordsTool::class, [
            'resource_key' => 'speakers',
            'record_key' => (string) $speaker->getKey(),
            'relation' => 'events',
            'search' => $matchingEvent->title,
        ])
        ->assertOk();

    expect(collect($apiResponse->json('data'))->pluck('route_key')->all())
        ->toEqual(collect(adminMcpStructuredContent($mcpResponse)['data'] ?? [])->pluck('route_key')->all())
        ->toContain((string) $matchingEvent->getRouteKey());
});

it('keeps admin api and admin mcp validate-only update previews aligned', function () {
    parityEnsureMalaysiaCountryExists();

    $admin = parityAdminUser('super_admin');
    $speaker = Speaker::factory()->create([
        'name' => 'Admin Parity Preview Speaker',
        'gender' => 'male',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $payload = [
        'name' => 'Admin Parity Preview Speaker Updated',
        'gender' => 'male',
        'status' => 'verified',
        'is_freelance' => true,
        'job_title' => 'Imam',
        'is_active' => true,
        'allow_public_event_submission' => true,
        'address' => [
            'country_id' => 132,
        ],
    ];

    Sanctum::actingAs($admin);

    $apiResponse = $this->putJson('/api/v1/admin/speakers/'.$speaker->getRouteKey().'?validate_only=1', $payload)
        ->assertOk();

    $mcpResponse = AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'speakers',
            'record_key' => (string) $speaker->getKey(),
            'validate_only' => true,
            'payload' => $payload,
        ])
        ->assertOk();

    $apiPreview = $apiResponse->json('data.preview');
    $mcpPreview = adminMcpStructuredContent($mcpResponse)['data']['preview'] ?? [];

    expect($apiPreview['operation'] ?? null)->toBe($mcpPreview['operation'] ?? null)
        ->and($apiPreview['normalized_payload'] ?? [])->toEqual($mcpPreview['normalized_payload'] ?? [])
        ->and(data_get($apiPreview, 'current_record.route_key'))->toBe(data_get($mcpPreview, 'current_record.route_key'));
});

it('keeps admin api and admin mcp event moderation workflows aligned', function () {
    $admin = parityAdminUser('super_admin');
    $submitter = User::factory()->create();

    $apiEvent = Event::factory()->create([
        'status' => 'pending',
        'submitter_id' => $submitter->getKey(),
    ]);

    $mcpEvent = Event::factory()->create([
        'status' => 'pending',
        'submitter_id' => $submitter->getKey(),
    ]);

    Sanctum::actingAs($admin);

    $apiSchemaResponse = $this->getJson('/api/v1/admin/events/'.$apiEvent->getRouteKey().'/moderation-schema')
        ->assertOk();

    $mcpSchemaResponse = AdminServer::actingAs($admin)
        ->tool(AdminGetEventModerationSchemaTool::class, [
            'record_key' => $apiEvent->getKey(),
        ])
        ->assertOk();

    expect($apiSchemaResponse->json('data'))->toEqual(adminMcpStructuredContent($mcpSchemaResponse)['data'] ?? []);

    $payload = [
        'action' => 'request_changes',
        'reason_code' => 'missing_venue',
        'note' => 'Please confirm the venue.',
    ];

    $apiActionResponse = $this->postJson('/api/v1/admin/events/'.$apiEvent->getRouteKey().'/moderate', $payload)
        ->assertOk();

    $mcpActionResponse = AdminServer::actingAs($admin)
        ->tool(AdminModerateEventTool::class, [
            'record_key' => $mcpEvent->getKey(),
            ...$payload,
        ])
        ->assertOk();

    $apiAction = $apiActionResponse->json('data');
    $mcpAction = adminMcpStructuredContent($mcpActionResponse)['data'] ?? [];

    expect(data_get($apiAction, 'resource.key'))->toBe(data_get($mcpAction, 'resource.key'))
        ->and(paritySelectedAttributes(data_get($apiAction, 'record.attributes', []), ['status']))
        ->toEqual(paritySelectedAttributes(data_get($mcpAction, 'record.attributes', []), ['status']))
        ->and((string) $apiEvent->fresh()?->status)->toBe((string) $mcpEvent->fresh()?->status)
        ->and((string) $apiEvent->fresh()?->status)->toBe('needs_changes');

    $apiReview = ModerationReview::query()->where('event_id', $apiEvent->getKey())->latest()->first();
    $mcpReview = ModerationReview::query()->where('event_id', $mcpEvent->getKey())->latest()->first();

    expect(paritySelectedAttributes($apiReview?->toArray() ?? [], ['decision', 'reason_code', 'note', 'moderator_id']))
        ->toEqual(paritySelectedAttributes($mcpReview?->toArray() ?? [], ['decision', 'reason_code', 'note', 'moderator_id']));
});

it('keeps admin api and admin mcp report triage workflows aligned', function () {
    $admin = parityAdminUser('super_admin');

    $apiReporter = User::factory()->create();
    $mcpReporter = User::factory()->create();

    $apiReport = Report::factory()->create([
        'reporter_id' => $apiReporter->getKey(),
        'status' => 'open',
        'handled_by' => null,
        'resolution_note' => null,
    ]);

    $mcpReport = Report::factory()->create([
        'reporter_id' => $mcpReporter->getKey(),
        'status' => 'open',
        'handled_by' => null,
        'resolution_note' => null,
    ]);

    Sanctum::actingAs($admin);

    $apiSchemaResponse = $this->getJson('/api/v1/admin/reports/'.$apiReport->getRouteKey().'/triage-schema')
        ->assertOk();

    $mcpSchemaResponse = AdminServer::actingAs($admin)
        ->tool(AdminGetReportTriageSchemaTool::class, [
            'record_key' => $apiReport->getKey(),
        ])
        ->assertOk();

    expect($apiSchemaResponse->json('data'))->toEqual(adminMcpStructuredContent($mcpSchemaResponse)['data'] ?? []);

    $payload = [
        'action' => 'resolve',
        'resolution_note' => 'Handled through the parity test.',
    ];

    $apiActionResponse = $this->postJson('/api/v1/admin/reports/'.$apiReport->getRouteKey().'/triage', $payload)
        ->assertOk();

    $mcpActionResponse = AdminServer::actingAs($admin)
        ->tool(AdminTriageReportTool::class, [
            'record_key' => $mcpReport->getKey(),
            ...$payload,
        ])
        ->assertOk();

    $apiAction = $apiActionResponse->json('data');
    $mcpAction = adminMcpStructuredContent($mcpActionResponse)['data'] ?? [];

    expect(data_get($apiAction, 'resource.key'))->toBe(data_get($mcpAction, 'resource.key'))
        ->and(paritySelectedAttributes(data_get($apiAction, 'record.attributes', []), ['status', 'handled_by', 'resolution_note']))
        ->toEqual(paritySelectedAttributes(data_get($mcpAction, 'record.attributes', []), ['status', 'handled_by', 'resolution_note']))
        ->and($apiReport->fresh()?->status)->toBe($mcpReport->fresh()?->status)
        ->and($apiReport->fresh()?->handled_by)->toBe($admin->getKey())
        ->and($mcpReport->fresh()?->handled_by)->toBe($admin->getKey())
        ->and($apiReport->fresh()?->resolution_note)->toBe('Handled through the parity test.')
        ->and($mcpReport->fresh()?->resolution_note)->toBe('Handled through the parity test.');
});

it('keeps admin api and admin mcp contribution request review workflows aligned', function () {
    $admin = parityAdminUser('super_admin');

    $apiInstitution = Institution::factory()->create([
        'description' => 'Before review',
        'status' => 'verified',
    ]);

    $mcpInstitution = Institution::factory()->create([
        'description' => 'Before review',
        'status' => 'verified',
    ]);

    $apiRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $apiInstitution->getMorphClass(),
        'entity_id' => $apiInstitution->getKey(),
        'status' => 'pending',
        'proposed_data' => [
            'description' => 'After review',
        ],
        'original_data' => [
            'description' => 'Before review',
        ],
    ]);

    $mcpRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $mcpInstitution->getMorphClass(),
        'entity_id' => $mcpInstitution->getKey(),
        'status' => 'pending',
        'proposed_data' => [
            'description' => 'After review',
        ],
        'original_data' => [
            'description' => 'Before review',
        ],
    ]);

    Sanctum::actingAs($admin);

    $apiSchemaResponse = $this->getJson('/api/v1/admin/contribution-requests/'.$apiRequest->getRouteKey().'/review-schema')
        ->assertOk();

    $mcpSchemaResponse = AdminServer::actingAs($admin)
        ->tool(AdminGetContributionRequestReviewSchemaTool::class, [
            'record_key' => $apiRequest->getKey(),
        ])
        ->assertOk();

    expect($apiSchemaResponse->json('data'))->toEqual(adminMcpStructuredContent($mcpSchemaResponse)['data'] ?? []);

    $payload = [
        'action' => 'approve',
        'reviewer_note' => 'Looks accurate.',
    ];

    $apiActionResponse = $this->postJson('/api/v1/admin/contribution-requests/'.$apiRequest->getRouteKey().'/review', $payload)
        ->assertOk();

    $mcpActionResponse = AdminServer::actingAs($admin)
        ->tool(AdminReviewContributionRequestTool::class, [
            'record_key' => $mcpRequest->getKey(),
            ...$payload,
        ])
        ->assertOk();

    $apiAction = $apiActionResponse->json('data');
    $mcpAction = adminMcpStructuredContent($mcpActionResponse)['data'] ?? [];

    expect(data_get($apiAction, 'resource.key'))->toBe(data_get($mcpAction, 'resource.key'))
        ->and(paritySelectedAttributes(data_get($apiAction, 'record.attributes', []), ['status', 'reviewer_id', 'reviewer_note', 'reason_code']))
        ->toEqual(paritySelectedAttributes(data_get($mcpAction, 'record.attributes', []), ['status', 'reviewer_id', 'reviewer_note', 'reason_code']))
        ->and($apiRequest->fresh()?->status?->value)->toBe($mcpRequest->fresh()?->status?->value)
        ->and($apiRequest->fresh()?->status?->value)->toBe('approved')
        ->and($apiInstitution->fresh()?->description)->toBe('After review')
        ->and($mcpInstitution->fresh()?->description)->toBe('After review');
});

it('keeps admin api and admin mcp membership claim review workflows aligned', function () {
    $admin = parityAdminUser('super_admin');

    $apiInstitution = Institution::factory()->create();
    $mcpInstitution = Institution::factory()->create();
    $apiClaimant = User::factory()->create();
    $mcpClaimant = User::factory()->create();

    $apiClaim = MembershipClaim::factory()
        ->forInstitution($apiInstitution)
        ->create([
            'claimant_id' => $apiClaimant->getKey(),
            'status' => 'pending',
        ]);

    $mcpClaim = MembershipClaim::factory()
        ->forInstitution($mcpInstitution)
        ->create([
            'claimant_id' => $mcpClaimant->getKey(),
            'status' => 'pending',
        ]);

    Sanctum::actingAs($admin);

    $apiSchemaResponse = $this->getJson('/api/v1/admin/membership-claims/'.$apiClaim->getRouteKey().'/review-schema')
        ->assertOk();

    $mcpSchemaResponse = AdminServer::actingAs($admin)
        ->tool(AdminGetMembershipClaimReviewSchemaTool::class, [
            'record_key' => $apiClaim->getKey(),
        ])
        ->assertOk();

    expect($apiSchemaResponse->json('data'))->toEqual(adminMcpStructuredContent($mcpSchemaResponse)['data'] ?? []);

    $payload = [
        'action' => 'approve',
        'granted_role_slug' => 'admin',
        'reviewer_note' => 'Approved through the parity test.',
    ];

    $apiActionResponse = $this->postJson('/api/v1/admin/membership-claims/'.$apiClaim->getRouteKey().'/review', $payload)
        ->assertOk();

    $mcpActionResponse = AdminServer::actingAs($admin)
        ->tool(AdminReviewMembershipClaimTool::class, [
            'record_key' => $mcpClaim->getKey(),
            ...$payload,
        ])
        ->assertOk();

    $apiAction = $apiActionResponse->json('data');
    $mcpAction = adminMcpStructuredContent($mcpActionResponse)['data'] ?? [];

    expect(data_get($apiAction, 'resource.key'))->toBe(data_get($mcpAction, 'resource.key'))
        ->and(paritySelectedAttributes(data_get($apiAction, 'record.attributes', []), ['status', 'granted_role_slug', 'reviewer_id', 'reviewer_note']))
        ->toEqual(paritySelectedAttributes(data_get($mcpAction, 'record.attributes', []), ['status', 'granted_role_slug', 'reviewer_id', 'reviewer_note']))
        ->and($apiClaim->fresh()?->status?->value)->toBe($mcpClaim->fresh()?->status?->value)
        ->and($apiClaim->fresh()?->status?->value)->toBe('approved')
        ->and($apiInstitution->fresh()?->members()->whereKey($apiClaimant->getKey())->exists())->toBeTrue()
        ->and($mcpInstitution->fresh()?->members()->whereKey($mcpClaimant->getKey())->exists())->toBeTrue();
});

it('keeps admin api and admin mcp tag create-update workflows aligned', function () {
    $admin = parityAdminUser('super_admin');

    Sanctum::actingAs($admin);

    $apiCreatePayload = [
        'name' => [
            'ms' => 'Parity Tag API '.Str::ulid(),
            'en' => 'Parity Tag API '.Str::ulid(),
        ],
        'type' => 'discipline',
        'status' => 'verified',
    ];

    $mcpCreatePayload = [
        'name' => [
            'ms' => 'Parity Tag MCP '.Str::ulid(),
            'en' => 'Parity Tag MCP '.Str::ulid(),
        ],
        'type' => 'discipline',
        'status' => 'verified',
    ];

    $apiCreateResponse = $this->postJson('/api/v1/admin/tags', $apiCreatePayload)
        ->assertCreated();

    $mcpCreateResponse = AdminServer::actingAs($admin)
        ->tool(AdminCreateRecordTool::class, [
            'resource_key' => 'tags',
            'payload' => $mcpCreatePayload,
        ])
        ->assertOk();

    expect(paritySelectedAttributes($apiCreateResponse->json('data.record.attributes') ?? [], ['type', 'status']))
        ->toEqual(paritySelectedAttributes(adminMcpStructuredContent($mcpCreateResponse)['data']['record']['attributes'] ?? [], ['type', 'status']));

    $apiRouteKey = (string) $apiCreateResponse->json('data.record.route_key');
    $mcpRouteKey = (string) data_get(adminMcpStructuredContent($mcpCreateResponse), 'data.record.route_key');

    expect($apiRouteKey)->not->toBe('')
        ->and($mcpRouteKey)->not->toBe('');

    $apiUpdateResponse = $this->putJson('/api/v1/admin/tags/'.$apiRouteKey, [
        'name' => [
            'ms' => 'Parity Tag API Updated',
            'en' => 'Parity Tag API Updated',
        ],
        'type' => 'issue',
        'status' => 'pending',
        'order_column' => 9,
    ])->assertOk();

    $mcpUpdateResponse = AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'tags',
            'record_key' => $mcpRouteKey,
            'payload' => [
                'name' => [
                    'ms' => 'Parity Tag MCP Updated',
                    'en' => 'Parity Tag MCP Updated',
                ],
                'type' => 'issue',
                'status' => 'pending',
                'order_column' => 9,
            ],
        ])
        ->assertOk();

    expect(paritySelectedAttributes($apiUpdateResponse->json('data.record.attributes') ?? [], ['type', 'status', 'order_column']))
        ->toEqual(paritySelectedAttributes(adminMcpStructuredContent($mcpUpdateResponse)['data']['record']['attributes'] ?? [], ['type', 'status', 'order_column']));

    $apiTag = Tag::query()->findOrFail($apiRouteKey);
    $mcpTag = Tag::query()->findOrFail($mcpRouteKey);

    expect(paritySelectedAttributes($apiTag->toArray(), ['type', 'status', 'order_column']))
        ->toEqual(paritySelectedAttributes($mcpTag->toArray(), ['type', 'status', 'order_column']));
});

function parityAdminUser(string $role): User
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

function parityEnsureMalaysiaCountryExists(): int
{
    $malaysiaId = DB::table('countries')->where('id', 132)->value('id');

    if (is_int($malaysiaId)) {
        return $malaysiaId;
    }

    return DB::table('countries')->insertGetId([
        'id' => 132,
        'iso2' => 'MY',
        'name' => 'Malaysia',
        'status' => 1,
        'phone_code' => '60',
        'iso3' => 'MYS',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);
}

/**
 * @return array<string, mixed>
 */
function adminMcpStructuredContent(McpTestResponse $response): array
{
    /** @var array<string, mixed> $structuredContent */
    $structuredContent = (fn (): array => $this->response->toArray()['result']['structuredContent'] ?? [])->call($response);

    return $structuredContent;
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function paritySelectedAttributes(array $attributes, array $keys): array
{
    return array_intersect_key($attributes, array_flip($keys));
}
