<?php

namespace App\Http\Controllers\Api;

use App\Data\Api\Notification\NotificationDestinationData as NotificationDestinationPayloadData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDestinationStatus;
use App\Http\Controllers\Controller;
use App\Models\NotificationDestination;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Notification Destination', 'Authenticated push-destination registration endpoints for device installations.')]
class NotificationDestinationController extends Controller
{
    #[BodyParameter('installation_id', 'Client installation identifier for the push destination.', type: 'string', infer: false, example: 'ios-installation-123')]
    #[BodyParameter('platform', 'Push platform for the current device.', type: 'string', infer: false, example: 'ios')]
    #[BodyParameter('fcm_token', 'Current FCM token for the device installation.', type: 'string', infer: false, example: 'fcm-token-abc123')]
    #[BodyParameter('app_version', 'Application version running on the device.', required: false, type: 'string', infer: false, example: '2.4.0')]
    #[BodyParameter('device_label', 'Human-readable label for the device.', required: false, type: 'string', infer: false, example: 'Nadia iPhone 15')]
    #[BodyParameter('locale', 'Preferred locale for push delivery.', required: false, type: 'string', infer: false, example: 'ms')]
    #[BodyParameter('timezone', 'Device timezone identifier.', required: false, type: 'string', infer: false, example: 'Asia/Kuala_Lumpur')]
    #[BodyParameter('last_seen_at', 'Last activity timestamp reported by the client.', required: false, type: 'string', infer: false, example: '2026-04-16T09:30:00Z')]
    #[Endpoint(
        title: 'Register or replace a push installation',
        description: 'Registers a new push destination or replaces the current device installation metadata for the authenticated user.',
    )]
    public function storePush(Request $request): JsonResponse
    {
        $validated = $this->validatePushPayload($request);
        $user = $this->currentUser($request);

        $destination = NotificationDestination::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => NotificationChannel::Push->value,
                'address' => $validated['installation_id'],
            ],
            [
                'external_id' => $validated['fcm_token'],
                'status' => NotificationDestinationStatus::Active->value,
                'is_primary' => false,
                'verified_at' => now(),
                'meta' => $this->pushMeta($validated),
            ],
        );

        return response()->json([
            'message' => __('notifications.api.push_registered'),
            'data' => NotificationDestinationPayloadData::fromModel($destination)->toArray(),
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
        description: 'Updates the metadata or token for an existing push installation owned by the authenticated user.',
    )]
    public function updatePush(Request $request, string $installation): JsonResponse
    {
        $validated = $this->validatePushPayload($request, $installation);
        $user = $this->currentUser($request);

        $destination = $user->notificationDestinations()
            ->where('channel', NotificationChannel::Push->value)
            ->where('address', $installation)
            ->firstOrFail();

        $destination->forceFill([
            'external_id' => $validated['fcm_token'],
            'status' => NotificationDestinationStatus::Active->value,
            'verified_at' => now(),
            'meta' => $this->pushMeta($validated),
        ])->save();

        return response()->json([
            'message' => __('notifications.api.push_updated'),
            'data' => NotificationDestinationPayloadData::fromModel($destination->fresh() ?? $destination)->toArray(),
        ]);
    }

    #[PathParameter('installation', 'Existing device installation identifier returned by the client application.', example: 'ios-installation-123')]
    #[Endpoint(
        title: 'Delete a push installation',
        description: 'Removes one push installation registration owned by the authenticated user.',
    )]
    public function destroyPush(Request $request, string $installation): Response
    {
        $this->currentUser($request)
            ->notificationDestinations()
            ->where('channel', NotificationChannel::Push->value)
            ->where('address', $installation)
            ->delete();

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
     * @return array<string, mixed>
     */
    protected function pushMeta(array $validated): array
    {
        return [
            'platform' => (string) $validated['platform'],
            'app_version' => (string) ($validated['app_version'] ?? ''),
            'device_label' => (string) ($validated['device_label'] ?? ''),
            'locale' => (string) ($validated['locale'] ?? ''),
            'timezone' => (string) ($validated['timezone'] ?? ''),
            'last_seen_at' => isset($validated['last_seen_at']) ? (string) $validated['last_seen_at'] : now()->toIso8601String(),
        ];
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
