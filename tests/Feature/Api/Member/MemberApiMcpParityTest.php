<?php

declare(strict_types=1);

use App\Actions\Membership\AddMemberToSubject;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Member\MemberApproveContributionRequestTool;
use App\Mcp\Tools\Member\MemberCancelContributionRequestTool;
use App\Mcp\Tools\Member\MemberCancelMembershipClaimTool;
use App\Mcp\Tools\Member\MemberListContributionRequestsTool;
use App\Mcp\Tools\Member\MemberListMembershipClaimsTool;
use App\Mcp\Tools\Member\MemberRejectContributionRequestTool;
use App\Mcp\Tools\Member\MemberSubmitMembershipClaimTool;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;
use Laravel\Sanctum\Sanctum;

it('keeps member api and member mcp contribution request listings aligned', function () {
    [$member, $reviewableInstitution] = memberParityAccessContext('admin');

    $ownInstitution = Institution::factory()->create([
        'name' => 'Member Parity Own Contribution Subject '.Str::ulid(),
        'description' => 'Own request original description.',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $reviewer = User::factory()->create();

    $ownRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $ownInstitution->getMorphClass(),
        'entity_id' => $ownInstitution->getKey(),
        'proposer_id' => $member->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Own request proposed description.',
        ],
        'original_data' => [
            'description' => 'Own request original description.',
        ],
    ]);

    $reviewableRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $reviewableInstitution->getMorphClass(),
        'entity_id' => $reviewableInstitution->getKey(),
        'proposer_id' => $reviewer->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Review request proposed description.',
        ],
        'original_data' => [
            'description' => 'Review request original description.',
        ],
    ]);

    Sanctum::actingAs($member);

    $apiResponse = $this->getJson(route('api.client.contributions.index'))
        ->assertOk();

    $mcpResponse = MemberServer::actingAs($member)
        ->tool(MemberListContributionRequestsTool::class)
        ->assertOk();

    expect(memberParityNormalizeContributionRequestListData($apiResponse->json('data') ?? []))
        ->toEqual(memberParityNormalizeContributionRequestListData(memberMcpStructuredContent($mcpResponse)['data'] ?? []))
        ->and(collect($apiResponse->json('data.my_requests') ?? [])->pluck('id')->all())->toContain($ownRequest->getKey())
        ->and(collect($apiResponse->json('data.pending_approvals') ?? [])->pluck('id')->all())->toContain($reviewableRequest->getKey());
});

it('keeps member api and member mcp contribution request actions aligned', function () {
    [$member] = memberParityAccessContext('admin');
    $memberServer = MemberServer::actingAs($member);
    $proposer = User::factory()->create();

    $makeReviewablePair = function (string $label, string $originalDescription, string $updatedDescription) use ($member, $proposer): array {
        $apiInstitution = Institution::factory()->create([
            'name' => 'Member Parity '.$label.' Contribution Subject',
            'description' => $originalDescription,
            'status' => 'verified',
            'is_active' => true,
        ]);

        $mcpInstitution = Institution::factory()->create([
            'name' => 'Member Parity '.$label.' Contribution Subject',
            'description' => $originalDescription,
            'status' => 'verified',
            'is_active' => true,
        ]);

        app(AddMemberToSubject::class)->handle($apiInstitution, $member, 'admin');
        app(AddMemberToSubject::class)->handle($mcpInstitution, $member, 'admin');

        $apiRequest = ContributionRequest::factory()->create([
            'type' => ContributionRequestType::Update,
            'subject_type' => ContributionSubjectType::Institution,
            'entity_type' => $apiInstitution->getMorphClass(),
            'entity_id' => $apiInstitution->getKey(),
            'proposer_id' => $proposer->getKey(),
            'proposer_note' => 'Member parity proposer note.',
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => [
                'description' => $updatedDescription,
            ],
            'original_data' => [
                'description' => $originalDescription,
            ],
        ]);

        $mcpRequest = ContributionRequest::factory()->create([
            'type' => ContributionRequestType::Update,
            'subject_type' => ContributionSubjectType::Institution,
            'entity_type' => $mcpInstitution->getMorphClass(),
            'entity_id' => $mcpInstitution->getKey(),
            'proposer_id' => $proposer->getKey(),
            'proposer_note' => 'Member parity proposer note.',
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => [
                'description' => $updatedDescription,
            ],
            'original_data' => [
                'description' => $originalDescription,
            ],
        ]);

        return [$apiInstitution, $apiRequest, $mcpInstitution, $mcpRequest];
    };

    [$apiApproveInstitution, $apiApproveRequest, $mcpApproveInstitution, $mcpApproveRequest] = $makeReviewablePair(
        'Approve',
        'Approve request original description.',
        'Approve request proposed description.',
    );

    $apiApproveResponse = $this->postJson(route('api.client.contributions.approve', ['requestId' => $apiApproveRequest->getKey()]), [
        'reviewer_note' => 'Approved through member parity.',
    ])->assertOk();

    $mcpApproveResponse = $memberServer
        ->tool(MemberApproveContributionRequestTool::class, [
            'request_id' => $mcpApproveRequest->getKey(),
            'reviewer_note' => 'Approved through member parity.',
        ])
        ->assertOk();

    expect(memberParityContributionRequestSnapshot($apiApproveResponse->json('data.request') ?? []))
        ->toEqual(memberParityContributionRequestSnapshot(memberMcpStructuredContent($mcpApproveResponse)['data']['request'] ?? []))
        ->and($apiApproveInstitution->fresh()?->description)->toBe('Approve request proposed description.')
        ->and($mcpApproveInstitution->fresh()?->description)->toBe('Approve request proposed description.');

    [$apiRejectInstitution, $apiRejectRequest, $mcpRejectInstitution, $mcpRejectRequest] = $makeReviewablePair(
        'Reject',
        'Reject request original description.',
        'Reject request proposed description.',
    );

    $apiRejectResponse = $this->postJson(route('api.client.contributions.reject', ['requestId' => $apiRejectRequest->getKey()]), [
        'reason_code' => 'needs_more_evidence',
        'reviewer_note' => 'Need stronger evidence.',
    ])->assertOk();

    $mcpRejectResponse = $memberServer
        ->tool(MemberRejectContributionRequestTool::class, [
            'request_id' => $mcpRejectRequest->getKey(),
            'reason_code' => 'needs_more_evidence',
            'reviewer_note' => 'Need stronger evidence.',
        ])
        ->assertOk();

    expect(memberParityContributionRequestSnapshot($apiRejectResponse->json('data.request') ?? []))
        ->toEqual(memberParityContributionRequestSnapshot(memberMcpStructuredContent($mcpRejectResponse)['data']['request'] ?? []))
        ->and($apiRejectInstitution->fresh()?->description)->toBe('Reject request original description.')
        ->and($mcpRejectInstitution->fresh()?->description)->toBe('Reject request original description.');

    $cancelSubjectName = 'Member Parity Cancel Contribution Subject '.Str::ulid();

    $apiCancelInstitution = Institution::factory()->create([
        'name' => $cancelSubjectName,
        'description' => 'Cancel request original description.',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $mcpCancelInstitution = Institution::factory()->create([
        'name' => $cancelSubjectName,
        'description' => 'Cancel request original description.',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $apiCancelRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $apiCancelInstitution->getMorphClass(),
        'entity_id' => $apiCancelInstitution->getKey(),
        'proposer_id' => $member->getKey(),
        'proposer_note' => 'Member parity proposer note.',
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Cancel request proposed description.',
        ],
        'original_data' => [
            'description' => 'Cancel request original description.',
        ],
    ]);

    $mcpCancelRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $mcpCancelInstitution->getMorphClass(),
        'entity_id' => $mcpCancelInstitution->getKey(),
        'proposer_id' => $member->getKey(),
        'proposer_note' => 'Member parity proposer note.',
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'Cancel request proposed description.',
        ],
        'original_data' => [
            'description' => 'Cancel request original description.',
        ],
    ]);

    $apiCancelResponse = $this->postJson(route('api.client.contributions.cancel', ['requestId' => $apiCancelRequest->getKey()]))
        ->assertOk();

    $mcpCancelResponse = $memberServer
        ->tool(MemberCancelContributionRequestTool::class, [
            'request_id' => $mcpCancelRequest->getKey(),
        ])
        ->assertOk();

    expect(memberParityContributionRequestSnapshot($apiCancelResponse->json('data.request') ?? []))
        ->toEqual(memberParityContributionRequestSnapshot(memberMcpStructuredContent($mcpCancelResponse)['data']['request'] ?? []))
        ->and($apiCancelRequest->fresh()?->status?->value)->toBe('cancelled')
        ->and($mcpCancelRequest->fresh()?->status?->value)->toBe('cancelled');
});

it('keeps member api and member mcp membership claim listings aligned', function () {
    [$member] = memberParityAccessContext('admin');

    $pendingClaimTarget = Institution::factory()->create([
        'name' => 'Member Parity Pending Claim Subject '.Str::ulid(),
        'status' => 'verified',
        'is_active' => true,
    ]);

    $cancelledClaimTarget = Institution::factory()->create([
        'name' => 'Member Parity Cancelled Claim Subject '.Str::ulid(),
        'status' => 'verified',
        'is_active' => true,
    ]);

    MembershipClaim::factory()
        ->forInstitution($pendingClaimTarget)
        ->create([
            'claimant_id' => $member->getKey(),
            'status' => MembershipClaimStatus::Pending,
            'justification' => 'Pending claim justification.',
        ]);

    MembershipClaim::factory()
        ->forInstitution($cancelledClaimTarget)
        ->create([
            'claimant_id' => $member->getKey(),
            'status' => MembershipClaimStatus::Cancelled,
            'justification' => 'Cancelled claim justification.',
            'cancelled_at' => now(),
        ]);

    Sanctum::actingAs($member);

    $apiResponse = $this->getJson(route('api.client.membership-claims.index'))
        ->assertOk();

    $mcpResponse = MemberServer::actingAs($member)
        ->tool(MemberListMembershipClaimsTool::class)
        ->assertOk();

    expect($apiResponse->json('data'))->toEqual(memberMcpStructuredContent($mcpResponse)['data'] ?? []);
});

it('keeps member api and member mcp membership claim actions aligned', function () {
    [$member] = memberParityAccessContext('admin');
    $memberServer = MemberServer::actingAs($member);

    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $apiSubmitTarget = Institution::factory()->create([
        'name' => 'Member Parity Claim Submission Subject',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $mcpSubmitTarget = Institution::factory()->create([
        'name' => 'Member Parity Claim Submission Subject',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $apiSubmitResponse = $this->post(
        route('api.client.membership-claims.store', [
            'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
            'subject' => $apiSubmitTarget->getKey(),
        ]),
        [
            'justification' => 'I help manage this institution.',
            'evidence' => [fakeGeneratedImageUpload('member-parity-claim-evidence.png')],
        ],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $mcpSubmitResponse = $memberServer
        ->tool(MemberSubmitMembershipClaimTool::class, [
            'subject_type' => MemberSubjectType::Institution->value,
            'subject' => $mcpSubmitTarget->getKey(),
            'justification' => 'I help manage this institution.',
            'evidence' => [memberParityImageDescriptor('member-parity-claim-evidence.png')],
        ])
        ->assertOk();

    expect(memberParityMembershipClaimSnapshot($apiSubmitResponse->json('data.claim') ?? []))
        ->toEqual(memberParityMembershipClaimSnapshot(memberMcpStructuredContent($mcpSubmitResponse)['data']['claim'] ?? []))
        ->and(memberParityMembershipClaimSubjectSnapshot($apiSubmitResponse->json('data.subject') ?? []))
        ->toEqual(memberParityMembershipClaimSubjectSnapshot(memberMcpStructuredContent($mcpSubmitResponse)['data']['subject'] ?? []))
        ->and($apiSubmitTarget->fresh()?->status)->toBe('verified')
        ->and($mcpSubmitTarget->fresh()?->status)->toBe('verified');

    $apiCancelTarget = Institution::factory()->create([
        'name' => 'Member Parity Claim Cancel Subject',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $mcpCancelTarget = Institution::factory()->create([
        'name' => 'Member Parity Claim Cancel Subject',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $apiClaim = MembershipClaim::factory()
        ->forInstitution($apiCancelTarget)
        ->create([
            'claimant_id' => $member->getKey(),
            'status' => MembershipClaimStatus::Pending,
            'justification' => 'Cancel claim justification.',
        ]);

    $mcpClaim = MembershipClaim::factory()
        ->forInstitution($mcpCancelTarget)
        ->create([
            'claimant_id' => $member->getKey(),
            'status' => MembershipClaimStatus::Pending,
            'justification' => 'Cancel claim justification.',
        ]);

    $apiCancelResponse = $this->deleteJson(route('api.client.membership-claims.cancel', ['claimId' => $apiClaim->getKey()]))
        ->assertOk();

    $mcpCancelResponse = $memberServer
        ->tool(MemberCancelMembershipClaimTool::class, [
            'claim_id' => $mcpClaim->getKey(),
        ])
        ->assertOk();

    expect(memberParityMembershipClaimSnapshot($apiCancelResponse->json('data.claim') ?? []))
        ->toEqual(memberParityMembershipClaimSnapshot(memberMcpStructuredContent($mcpCancelResponse)['data']['claim'] ?? []))
        ->and($apiClaim->fresh()?->status?->value)->toBe('cancelled')
        ->and($mcpClaim->fresh()?->status?->value)->toBe('cancelled');
});

function memberParityAccessContext(string $role = 'admin', string $status = 'verified'): array
{
    $institution = Institution::factory()->create([
        'status' => $status,
        'is_active' => true,
    ]);

    $member = User::factory()->create([
        'phone' => memberParityPhone(),
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, $role);

    return [$member, $institution];
}

/**
 * @return array<string, mixed>
 */
function memberMcpStructuredContent(McpTestResponse $response): array
{
    /** @var array<string, mixed> $structuredContent */
    $structuredContent = (fn (): array => $this->response->toArray()['result']['structuredContent'] ?? [])->call($response);

    return $structuredContent;
}

/**
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function memberParityContributionRequestSnapshot(array $request): array
{
    return [
        'type' => $request['type'] ?? null,
        'type_label' => $request['type_label'] ?? null,
        'subject_type' => $request['subject_type'] ?? null,
        'subject_label' => $request['subject_label'] ?? null,
        'entity_title' => $request['entity_title'] ?? null,
        'status' => $request['status'] ?? null,
        'status_label' => $request['status_label'] ?? null,
        'reason_code' => memberParityNormalizeOptionalText($request['reason_code'] ?? null),
        'proposer_note' => memberParityNormalizeOptionalText($request['proposer_note'] ?? null),
        'reviewer_note' => memberParityNormalizeOptionalText($request['reviewer_note'] ?? null),
        'changed_fields' => $request['changed_fields'] ?? [],
        'can_cancel' => $request['can_cancel'] ?? null,
        'proposer' => $request['proposer'] ?? null,
        'reviewer' => $request['reviewer'] ?? null,
    ];
}

/**
 * @param  array<string, mixed>  $claim
 * @return array<string, mixed>
 */
function memberParityMembershipClaimSnapshot(array $claim): array
{
    return [
        'subject_type' => $claim['subject_type'] ?? null,
        'subject_label' => $claim['subject_label'] ?? null,
        'subject_title' => $claim['subject_title'] ?? null,
        'status' => $claim['status'] ?? null,
        'status_label' => $claim['status_label'] ?? null,
        'role_label' => $claim['role_label'] ?? null,
        'justification' => $claim['justification'] ?? null,
        'granted_role_slug' => memberParityNormalizeOptionalText($claim['granted_role_slug'] ?? null),
        'reviewer_note' => memberParityNormalizeOptionalText($claim['reviewer_note'] ?? null),
        'can_cancel' => $claim['can_cancel'] ?? null,
        'reviewer' => $claim['reviewer'] ?? null,
        'evidence_count' => count($claim['evidence'] ?? []),
        'evidence_names' => collect($claim['evidence'] ?? [])->pluck('name')->values()->all(),
    ];
}

/**
 * @param  array<string, mixed>  $subject
 * @return array<string, mixed>
 */
function memberParityMembershipClaimSubjectSnapshot(array $subject): array
{
    return [
        'subject_label' => $subject['subject_label'] ?? null,
        'subject_title' => $subject['subject_title'] ?? null,
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function memberParityNormalizeContributionRequestListData(array $data): array
{
    foreach (['my_requests', 'pending_approvals'] as $bucket) {
        if (! isset($data[$bucket]) || ! is_array($data[$bucket])) {
            continue;
        }

        $data[$bucket] = array_map(
            static function (array $request): array {
                unset($request['can_review']);

                $request['reason_code'] = memberParityNormalizeOptionalText($request['reason_code'] ?? null);
                $request['proposer_note'] = memberParityNormalizeOptionalText($request['proposer_note'] ?? null);
                $request['reviewer_note'] = memberParityNormalizeOptionalText($request['reviewer_note'] ?? null);

                return $request;
            },
            $data[$bucket],
        );
    }

    return $data;
}

function memberParityNormalizeOptionalText(mixed $value): ?string
{
    if (! is_string($value)) {
        return null;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? null : $trimmed;
}

/**
 * @return array{filename: string, mime_type: string, content_base64: string}
 */
function memberParityImageDescriptor(string $name): array
{
    $upload = fakeGeneratedImageUpload($name, 640, 480);
    $contents = file_get_contents((string) $upload->getRealPath());

    if (! is_string($contents) || $contents === '') {
        throw new RuntimeException('Unable to create member MCP image descriptor.');
    }

    return [
        'filename' => $name,
        'mime_type' => 'image/png',
        'content_base64' => base64_encode($contents),
    ];
}

function memberParityPhone(): string
{
    return '+6011'.random_int(10000000, 99999999);
}
