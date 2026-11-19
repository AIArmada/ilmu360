<?php

declare(strict_types=1);

namespace App\Platform\Telemetry\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class MobileTelemetryRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post mobile/telemetry/events' => [
                'anonymous_id' => 'ios-installation-123',
                'session_identifier' => 'ios-session-2026-04-22T10:00:00Z',
                'session_started_at' => '2026-04-22T10:00:00Z',
                'events' => [
                    [
                        'event_name' => 'screen.viewed',
                        'event_category' => 'navigation',
                        'occurred_at' => '2026-04-22T10:00:05Z',
                        'path' => '/home',
                        'screen_name' => 'home',
                        'properties' => [
                            'entrypoint' => 'push_notification',
                        ],
                    ],
                    [
                        'event_name' => 'ui.clicked',
                        'event_category' => 'engagement',
                        'occurred_at' => '2026-04-22T10:00:18Z',
                        'path' => '/events/weekly-kuliah',
                        'screen_name' => 'event_detail',
                        'component' => 'register_button',
                        'action' => 'tap',
                    ],
                ],
            ],
        ];
    }
}
