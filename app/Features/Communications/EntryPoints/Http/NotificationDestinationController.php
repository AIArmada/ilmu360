<?php

namespace App\Features\Communications\EntryPoints\Http;

use App\Features\Communications\EntryPoints\Http\Data\NotificationDestinationData as NotificationDestinationPayloadData;
use App\Features\Communications\Api\PushDestinationDetails;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Api\PushDestinationSnapshot;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Notification Destination', 'Authenticated push-destination registration endpoints for device installations.')]
class NotificationDestinationController
{
    public function __construct(
        private readonly PushDestinationManagement $pushDestinations,
    ) {}

    #[BodyParameter('installation_id', 'Client installation identifier for the push destination.', type: 'string', infer: false, example: 'ios-installation-123')]
    #[BodyParameter('platform', 'Push platform for the current device.', type: 'string', infer: false, example: 'ios')]
    #[BodyParameter('fcm_token', 'Current FCM token for the device installation.', type: 'string', infer: false, example: 'fcm-token-abc123')]
    #[BodyParameter('app_version', 'Application version running on the device.', required: false, type: 'string', infer: false, example: '2.4.0')]
    #[BodyParameter('device_label', 'Human-readable label for the device.', required: false, type: 'string', infer: false, example: 'Nadia iPhone 15')]
    #[BodyParameter('locale', 'Preferred locale for push delivery.', required: false, type: 'string', infer: false, example: 'ms')]
    #[BodyParameter('timezone', 'Device timezone identifier.', required: false, type: 'string', infer: false, example: 'Asia/Kuala_Lumpur')]
    #[BodyParameter('last_seen_at', 'Last activity timestamp reported by the client.', required: false, type: 'string', infer: false, example: '2026-04-16T09:30:00Z')]
    #[Endpoint(
        title: 'Register a push installation',
        description: 'Registers a new push destination for the authenticated user.',
    )]
    public function storePush(Request $request): JsonResponse
    {
        $validated = $this->validatePushPayload($request);

        $destination = $this->pushDestinations->register(
            $this->currentAccountId($request),
            (string) $validated['installation_id'],
            $this->details($validated),
        );

        return response()->json([
            'message' => __('notifications.api.push_registered'),
            'data' => NotificationDestinationPayloadData::fromSnapshot($destination)->toArray(),
        ], 201);
    }

    #[PathParameter('installation', 'Existing device installation identifier returned by the client application.', example: 'ios-installation-123')]
    #[BodyParameter('platform', 'Push platform for the current device.', type: 'string', infer: false, example: 'ios')]
    #[BodyParameter('fcm_token', 'Current FCM token for the device installation.', type: 'string', infer: false, example: 'fcm-token-updated-xyz789')]
    #[BodyParameter('app_version', 'Application version running on the device.', required: false, type: 'string', infer: false, example: '2.4.1')]
    #[BodyParameter('device_label', 'Human-readable label for the device.', required: false, type: 'string', infer: false, example: 'Nadia iPhone 15 Pro')]
    #[BodyParameter('locale', 'Preferred locale for push delivery.', required: false, type: 'string', infer: false, example: 'ms')]
    #[BodyParameter('timezone', 'Device timezone identifier.', required: false, type: 'string', infer: false, example: 'Asia/Kuala_Lumpur')]
    #[BodyParameter('last_seen_at', 'Last activity timestamp reported by the client.', required: false, type: 'string', infer: false, example: '2026-04-16T10:45:00Z')]
    #[Endpoint(
        title: 'Update a push installation',
        description: 'Updates the token or device details for an existing push installation owned by the authenticated user.',
    )]
    public function updatePush(Request $request, string $installation): JsonResponse
    {
        $validated = $this->validatePushPayload($request, $installation);

        $destination = $this->pushDestinations->update(
            $this->currentAccountId($request),
            $installation,
            $this->details($validated),
        );

        abort_unless($destination instanceof PushDestinationSnapshot, 404);

        return response()->json([
            'message' => __('notifications.api.push_updated'),
            'data' => NotificationDestinationPayloadData::fromSnapshot($destination)->toArray(),
        ]);
    }

    #[PathParameter('installation', 'Existing device installation identifier returned by the client application.', example: 'ios-installation-123')]
    #[Endpoint(
        title: 'Delete a push installation',
        description: 'Removes one registered device installation owned by the authenticated user.',
    )]
    public function destroyPush(Request $request, string $installation): Response
    {
        $this->pushDestinations->deleteByInstallation(
            $this->currentAccountId($request),
            $installation,
        );

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePushPayload(Request $request, ?string $installation = null): array
    {
        return $request->validate([
            'installation_id' => [
                Rule::requiredIf($installation === null),
                'string',
                'max:255',
            ],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'fcm_token' => ['required', 'string', 'max:500'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'device_label' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', Rule::in(array_keys(config('app.supported_locales', [])))],
            'timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
            'last_seen_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function details(array $validated): PushDestinationDetails
    {
        $lastSeenAt = $validated['last_seen_at'] ?? null;

        return new PushDestinationDetails(
            platform: (string) $validated['platform'],
            fcmToken: (string) $validated['fcm_token'],
            appVersion: (string) ($validated['app_version'] ?? ''),
            deviceLabel: (string) ($validated['device_label'] ?? ''),
            locale: (string) ($validated['locale'] ?? ''),
            timezone: (string) ($validated['timezone'] ?? ''),
            lastSeenAt: is_string($lastSeenAt) ? $lastSeenAt : null,
        );
    }

    protected function currentAccountId(Request $request): string
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return (string) $user->getAuthIdentifier();
    }
}
