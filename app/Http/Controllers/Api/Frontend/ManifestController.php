<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Enums\MemberSubjectType;
use App\Support\Api\Frontend\FrontendFormContractService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(
    'Manifest',
    'Machine-readable public flow discovery and field contracts. '
    .'Use these endpoints first when you need defaults, required fields, catalogs, or conditional rules before posting data.',
    weight: 10,
)]
class ManifestController extends FrontendController
{
    public function __construct(
        private readonly FrontendFormContractService $contracts,
    ) {}

    #[Endpoint(
        title: 'Discover public client flows',
        description: 'Returns the public capability manifest for frontend and mobile clients. '
            .'Use it to discover available flows, auth requirements, schema endpoints, follow or update endpoint templates, docs URLs, routing-surface guidance, and AI-consumer operating rules.',
    )]
    public function manifest(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->manifest($this->currentUser($request)),
        ]);
    }

    #[Endpoint(
        title: 'Get submit-event field contract',
        description: 'Returns the canonical field contract for public event submission. '
            .'Use `fields`, `defaults`, `conditional_rules`, and catalog URLs here before calling `POST /submit-event`. '
            .'Event media uses two distinct image roles: `cover` (16:9 website/app visual) and `poster` (4:5 external distribution visual).',
    )]
    public function submitEvent(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->submitEvent($this->currentUser($request)),
        ]);
    }

    #[Endpoint(
        title: 'Get institution contribution field contract',
        description: 'Returns the canonical field contract for authenticated public institution creation. '
            .'Use this before calling `POST /contributions/institutions`. '
            .'Institution create requires an explicit address country via `address.country_id`. '
            .'Any provided `address.google_maps_url` is normalized server-side into a canonical Google Maps URL and may populate `address.provider_place_id`, `address.latitude`, and `address.longitude` before persistence.',
    )]
    public function submitInstitution(): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->submitInstitution(),
        ]);
    }

    #[Endpoint(
        title: 'Get speaker contribution field contract',
        description: 'Returns the canonical field contract for authenticated public speaker creation. '
            .'Use this before calling `POST /contributions/speakers`. '
            .'Speaker create requires an explicit address country via `address.country_id`. '
            .'Detailed street and map fields remain unsupported on this public speaker flow.',
    )]
    public function submitSpeaker(): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->submitPerson(),
        ]);
    }

    #[Endpoint(
        title: 'Get mobile-telemetry field contract',
        description: 'Returns the native mobile telemetry contract for real iOS, iPadOS, and Android app sessions. Use this before calling `POST /mobile/telemetry/events`. This route is not for mobile web browsing.',
    )]
    public function mobileTelemetry(): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->mobileTelemetry(),
        ]);
    }

    #[Endpoint(
        title: 'Get membership-claim field contract',
        description: 'Returns the field contract for claiming membership on a supported public subject type.',
    )]
    public function membershipClaim(string $subjectType): JsonResponse
    {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);
        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        return response()->json([
            'data' => $this->contracts->membershipClaim($resolvedSubjectType),
        ]);
    }

    #[Endpoint(
        title: 'Get report field contract',
        description: 'Returns the field contract for authenticated content or data reports before calling `POST /reports`.',
    )]
    public function report(): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->report(),
        ]);
    }

    #[Endpoint(
        title: 'Get GitHub issue-report field contract',
        description: 'Returns the authenticated field contract for reporting MCP or API issues directly to the ilmu360 GitHub repository before calling `POST /github-issues`.',
    )]
    public function githubIssueReport(): JsonResponse
    {
        abort_unless($this->contracts->supportsGitHubIssueReporting(), 404);

        return response()->json([
            'data' => $this->contracts->githubIssueReport(),
        ]);
    }

    #[Endpoint(
        title: 'Get account-settings field contract',
        description: 'Returns the authenticated account-settings contract, including the update endpoint and supporting catalog endpoints.',
    )]
    public function accountSettings(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return response()->json([
            'data' => $this->contracts->accountSettings($user),
        ]);
    }

    #[Endpoint(
        title: 'Get advanced-event field contract',
        description: 'Returns the authenticated field contract for creating an advanced event before calling `POST /advanced-events`.',
    )]
    public function advancedEvent(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return response()->json([
            'data' => $this->contracts->advancedEvent($user),
        ]);
    }

    #[Endpoint(
        title: 'Get institution-workspace field contract',
        description: 'Returns the authenticated member-management contract for institution workspaces, including the workspace endpoint, add/update/remove endpoint templates, and event filter defaults.',
    )]
    public function institutionWorkspace(): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->institutionWorkspace(),
        ]);
    }
}
