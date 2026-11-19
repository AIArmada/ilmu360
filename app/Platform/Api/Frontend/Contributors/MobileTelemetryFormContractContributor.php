<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend\Contributors;

use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class MobileTelemetryFormContractContributor implements FrontendFormContractContributor
{
    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::MOBILE_TELEMETRY;
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        return [
            'flow' => 'mobile_telemetry',
            'method' => 'POST',
            'endpoint' => route('api.client.mobile-telemetry.store'),
            'auth_required' => false,
            'bearer_auth_optional' => true,
            'native_only' => true,
            'intended_clients' => ['ios', 'ipados', 'android'],
            'required_headers' => [
                [
                    'name' => 'X-Ilmu360-Client-Origin',
                    'required' => true,
                    'allowed_values' => ['iosapp', 'ipadosapp', 'androidapp'],
                    'description' => 'Required native client hint. Mobile web browsing should keep using the browser tracker instead of this endpoint.',
                ],
                [
                    'name' => 'X-Ilmu360-Client-Name',
                    'required' => false,
                ],
                [
                    'name' => 'X-Ilmu360-Client-Version',
                    'required' => false,
                ],
                [
                    'name' => 'X-Ilmu360-Client-Build',
                    'required' => false,
                ],
            ],
            'defaults' => [
                'anonymous_id' => null,
                'session_identifier' => null,
                'session_started_at' => null,
                'events' => [],
            ],
            'fields' => [
                FrontendFormContractField::make('anonymous_id', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('session_identifier', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('session_started_at', 'datetime', required: false),
                FrontendFormContractField::make('events', 'array<object>', required: true),
            ],
            'event_fields' => [
                FrontendFormContractField::make('event_name', 'string', required: true, maxLength: 120),
                FrontendFormContractField::make('event_category', 'string', required: false, maxLength: 80),
                FrontendFormContractField::make('occurred_at', 'datetime', required: false),
                FrontendFormContractField::make('path', 'string', required: false, maxLength: 2048),
                FrontendFormContractField::make('url', 'string', required: false, maxLength: 2048),
                FrontendFormContractField::make('referrer', 'string', required: false, maxLength: 2048),
                FrontendFormContractField::make('screen_name', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('previous_screen_name', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('component', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('action', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('utm_source', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('utm_medium', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('utm_campaign', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('utm_content', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('utm_term', 'string', required: false, maxLength: 120),
                FrontendFormContractField::make('properties', 'object', required: false),
            ],
            'conditional_rules' => [
                ['field' => 'anonymous_id', 'required_when_missing' => ['session_identifier'], 'unless_authenticated' => true],
                ['field' => 'session_identifier', 'required_when_missing' => ['anonymous_id'], 'unless_authenticated' => true],
            ],
            'notes' => [
                'This endpoint is reserved for real native app sessions on iOS, iPadOS, and Android.',
                'Do not use this endpoint for mobile web page views; browser sessions should keep using the web signals tracker.',
                'Batch up to 50 events per request and send stable anonymous/session identifiers for guest sessions.',
            ],
        ];
    }
}
