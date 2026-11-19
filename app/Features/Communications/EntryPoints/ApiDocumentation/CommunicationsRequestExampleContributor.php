<?php

declare(strict_types=1);

namespace App\Features\Communications\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class CommunicationsRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'put notification-settings' => [
                'settings' => [
                    'channels' => ['in_app', 'email', 'push'],
                    'quiet_hours_start' => '22:00',
                    'quiet_hours_end' => '07:00',
                ],
                'families' => [
                    'event_updates' => [
                        'enabled' => true,
                        'cadence' => 'instant',
                    ],
                ],
                'triggers' => [
                    'checkin_open' => [
                        'enabled' => true,
                    ],
                ],
            ],
            'post notification-destinations/push' => [
                'installation_id' => 'ios-installation-123',
                'platform' => 'ios',
                'fcm_token' => 'fcm-token-abc123',
                'app_version' => '2.4.0',
                'device_label' => 'Nadia iPhone 15',
                'locale' => 'ms',
                'timezone' => 'Asia/Kuala_Lumpur',
                'last_seen_at' => '2026-04-16T09:30:00Z',
            ],
            'put notification-destinations/push/{installation}' => [
                'platform' => 'ios',
                'fcm_token' => 'fcm-token-updated-xyz789',
                'app_version' => '2.4.1',
                'device_label' => 'Nadia iPhone 15 Pro',
                'locale' => 'ms',
                'timezone' => 'Asia/Kuala_Lumpur',
                'last_seen_at' => '2026-04-16T10:45:00Z',
            ],
        ];
    }
}
