<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimResult;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

#[Group('Share', 'Explicit authenticated guest-share claims for web and native clients.')]
final class ShareClaimController extends Controller
{
    public function __construct(
        private readonly AffiliatesShareTrackingService $shareTrackingService,
        private readonly ClaimGuestShareProfileAction $claimAction,
    ) {}

    public function show(Request $request): View
    {
        $user = $this->authenticatedUser($request);

        return view('share.claim', [
            'state' => $this->shareTrackingService->claimPageState($user, $request)['state'],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // Validation reads merged query+body input, so the secret is
        // never taken from the validated payload: the central transport
        // reader below accepts only header, cookie, and non-cacheable
        // body transports, never a GET query.
        $request->validate([
            'confirm' => ['required', 'accepted'],
            'guest_claim_capability' => ['nullable', 'string', 'max:64'],
        ]);

        $presented = $this->shareTrackingService->presentedClaimCapability($request);
        $result = $this->claimAction->handle($user, (string) $presented, true);

        if ($request->expectsJson()) {
            return $this->jsonResult($result);
        }

        return match ($result) {
            ShareGuestClaimResult::Claimed,
            ShareGuestClaimResult::AlreadyClaimedByYou => redirect()
                ->route('share.claim.show')
                ->with('share_claim_status', 'claimed'),
            ShareGuestClaimResult::RecipientIneligible => redirect()
                ->route('share.claim.show')
                ->withErrors(['confirm' => __('Your share profile cannot receive past shares right now. Ask an administrator to review it.')]),
            ShareGuestClaimResult::ClaimedByOther,
            ShareGuestClaimResult::ExpiredOrInvalid => redirect()
                ->route('share.claim.show')
                ->withErrors(['confirm' => __('We could not verify those past shares as yours from this browser.')]),
        };
    }

    #[Endpoint(
        title: 'Check guest-claim availability',
        description: 'Reports whether the current session can be offered an explicit guest-share claim: true only when the request presents a usable claim capability for an unclaimed guest profile. Read-only; the claim itself needs a separate explicit confirmation.',
    )]
    public function availability(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        return response()
            ->json([
                'available' => $user instanceof User
                    && $this->shareTrackingService->claimPromptAvailable($user, $request),
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    #[Endpoint(
        title: 'Claim past anonymous shares',
        description: 'Links the guest share history proven by the presented private claim capability to the authenticated account. Requires explicit confirmation; public share tokens, email addresses, and device similarity never prove ownership.',
    )]
    #[BodyParameter('confirm', 'Must be true: explicit confirmation that the past shares on this device are yours.', type: 'boolean', infer: false, example: true)]
    #[BodyParameter('guest_claim_capability', 'Private claim capability issued with the anonymous share payload. May also travel in the X-Dawah-Claim-Capability header or the claim cookie. Never a public share token.', type: 'string', infer: false, example: 'Ab3dEf7hIj2kLm4nOp6qRs8tUv0wXy9z')]
    public function claim(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        if (! $user instanceof User) {
            abort(401);
        }

        // Validation reads merged query+body input, so the secret is
        // never taken from the validated payload: the central transport
        // reader below accepts only header, cookie, and non-cacheable
        // body transports, never a GET query.
        $request->validate([
            'confirm' => ['required', 'accepted'],
            'guest_claim_capability' => ['nullable', 'string', 'max:64'],
        ]);

        $presented = $this->shareTrackingService->presentedClaimCapability($request);

        // Validation above guarantees explicit confirmation, so the action
        // cannot throw for missing consent here.
        $result = $this->claimAction->handle($user, (string) $presented, true);

        return $this->jsonResult($result);
    }

    private function jsonResult(ShareGuestClaimResult $result): JsonResponse
    {
        // Claimed-by-other and expired-or-invalid share one generic
        // status, code, and message: the response reveals nothing about
        // which valid history another account owns.
        [$status, $message] = match ($result) {
            ShareGuestClaimResult::Claimed => [200, __('Your past shares are now linked to your account.')],
            ShareGuestClaimResult::AlreadyClaimedByYou => [200, __('Those past shares are already linked to your account.')],
            ShareGuestClaimResult::RecipientIneligible => [422, __('Your share profile cannot receive past shares right now. Ask an administrator to review it.')],
            ShareGuestClaimResult::ClaimedByOther,
            ShareGuestClaimResult::ExpiredOrInvalid => [422, __('We could not verify those past shares as yours from this browser.')],
        };

        $payload = ['result' => $result->publicCode(), 'message' => $message];

        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function apiUser(Request $request): ?User
    {
        $user = $request->user() ?? $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }
}
