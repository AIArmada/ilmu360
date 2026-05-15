<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use App\Support\Notifications\NotificationCatalog;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Notification Settings', 'Authenticated notification preference discovery and update endpoints.')]
class NotificationSettingsController extends Controller
{
    public function __construct(
        protected NotificationSettingsManager $settingsManager,
    ) {}

    #[Endpoint(
        title: 'Get notification catalog',
        description: 'Returns notification families, triggers, and selectable option sets for the current authenticated user.',
    )]
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $state = $this->settingsManager->stateFor($user);

        return response()->json([
            'data' => [
                'families' => collect(NotificationCatalog::families())
                    ->map(fn (array $definition): array => [
                        'key' => $definition['key'],
                        'label' => $definition['label'],
                        'description' => $definition['description'],
                        'default_cadence' => $definition['default_cadence']->value,
                        'allowed_channels' => $definition['allowed_channels'],
                        'default_channels' => $definition['default_channels'],
                        'triggers' => $definition['triggers'],
                    ])
                    ->values()
                    ->all(),
                'triggers' => collect(NotificationCatalog::triggers())
                    ->map(fn (array $definition): array => [
                        'key' => $definition['key'],
                        'family' => $definition['family']->value,
                        'label' => $definition['label'],
                        'description' => $definition['description'],
                        'default_cadence' => $definition['default_cadence']->value,
                        'allowed_channels' => $definition['allowed_channels'],
                        'default_channels' => $definition['default_channels'],
                        'priority' => $definition['priority']->value,
                    ])
                    ->values()
                    ->all(),
                'options' => $state['options'],
            ],
        ]);
    }

    #[Endpoint(
        title: 'Get current notification settings',
        description: 'Returns the current authenticated user\'s notification preferences and resolved delivery destinations.',
    )]
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->settingsManager->stateFor($this->currentUser($request)),
        ]);
    }

    #[Endpoint(
        title: 'Update notification settings',
        description: 'Updates notification preferences, digest timing, fallback strategy, and channel selections for the current authenticated user.',
    )]
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['sometimes', 'array'],
            'families' => ['sometimes', 'array'],
            'triggers' => ['sometimes', 'array'],
        ]);

        $user = $this->currentUser($request);
        $currentState = $this->settingsManager->stateFor($user);
        $payload = array_replace_recursive(
            [
                'settings' => $currentState['settings'],
                'families' => $currentState['families'],
                'triggers' => $currentState['triggers'],
            ],
            $validated,
        );

        return response()->json([
            'message' => __('notifications.flash.updated'),
            'data' => $this->settingsManager->save($user, $payload),
        ]);
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
