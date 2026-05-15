<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ShareTrackingService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('Share', 'Public share payload discovery and guest-or-authenticated share tracking endpoints for web, mobile, and native clients.')]
class DawahShareController extends Controller
{
    #[Endpoint(
        title: 'Build a share payload',
        description: 'Returns the canonical share URL, per-channel share URLs, platform links, and native-share payload data for a public ilmu360° URL. Logged-in users receive a personalized tracking token, anonymous browsers receive a reusable browser-scoped share token, and origin defaults to web unless a client explicitly provides another origin identifier.',
    )]
    #[QueryParameter('url', 'Required internal ilmu360° URL to share.', required: true, type: 'string', infer: false, example: 'https://ilmu360.test/events/my-event')]
    #[QueryParameter('text', 'Required share text used for social intents and native-share payloads.', required: true, type: 'string', infer: false, example: 'Join this majlis')]
    #[QueryParameter('title', 'Optional fallback title for the shared subject.', required: false, type: 'string', infer: false, example: 'Weekly Kuliah')]
    #[QueryParameter('origin', 'Optional share origin identifier. Defaults to web. Example values: web, iosapp, android, macapp, ipadOs. Future client identifiers are accepted and normalized to lowercase.', required: false, type: 'string', infer: false, default: 'web', example: 'iosapp')]
    public function payload(Request $request, ShareTrackingService $shareTrackingService): JsonResponse
    {
        $data = $this->validatedData($request);

        return response()->json($this->buildPayload($request, $shareTrackingService, $data));
    }

    public function redirect(string $provider, Request $request, ShareTrackingService $shareTrackingService): RedirectResponse
    {
        $data = $this->validatedData($request);

        try {
            return redirect()->away($shareTrackingService->redirectUrl(
                provider: $provider,
                user: $this->authenticatedUser($request),
                url: $data['url'],
                shareText: $data['text'],
                fallbackTitle: $data['title'] ?? null,
                origin: $data['origin'] ?? null,
                request: $request,
            ));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'url' => $exception->getMessage(),
            ]);
        }
    }

    #[Endpoint(
        title: 'Record a share action',
        description: 'Records an outbound share action for a previously issued tracking token. Use this for copy-link, native-share, or mobile-app share-sheet analytics after the client actually invokes the share action.',
    )]
    #[BodyParameter('provider', 'Share channel that the client actually used. Supported values include social providers plus `copy_link` and `native_share`.', type: 'string', infer: false, example: 'copy_link')]
    #[BodyParameter('tracking_token', 'Tracking token returned by `GET /share/payload` for authenticated or anonymous shares.', type: 'string', infer: false, example: 'abcd1234wxyz6789')]
    public function track(Request $request, ShareTrackingService $shareTrackingService): Response
    {
        $data = $this->validatedTrackedData($request, $shareTrackingService);

        try {
            $shareTrackingService->recordShareAction(
                provider: $data['provider'],
                user: $this->authenticatedUser($request),
                trackingToken: $data['tracking_token'],
                request: $request,
            );

            return response()->noContent();
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'tracking_token' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{url: string, text: string, title?: string, origin?: string}
     */
    protected function validatedData(Request $request): array
    {
        /** @var array{url: string, text: string, title?: string, origin?: string} $validated */
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'text' => ['required', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:255'],
            'origin' => ['nullable', 'string', 'max:64'],
        ]);

        return $validated;
    }

    /**
     * @return array{provider:string,tracking_token:string}
     */
    protected function validatedTrackedData(Request $request, ShareTrackingService $shareTrackingService): array
    {
        $providers = $shareTrackingService->supportedChannels();

        /** @var array{provider:string,tracking_token:string} $validated */
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers)],
            'tracking_token' => ['required', 'string'],
        ]);

        return $validated;
    }

    protected function authenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $user = $request->user('sanctum');
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array{url: string, text: string, title?: string, origin?: string}  $data
     * @return array<string, mixed>
     */
    protected function buildPayload(Request $request, ShareTrackingService $shareTrackingService, array $data): array
    {
        try {
            return $shareTrackingService->sharePayload(
                $this->authenticatedUser($request),
                $data['url'],
                $data['text'],
                $data['title'] ?? null,
                $data['origin'] ?? null,
                $request,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'url' => $exception->getMessage(),
            ]);
        }
    }
}
