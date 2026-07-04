<?php

declare(strict_types=1);

use AIArmada\Communications\Http\Middleware\VerifyWebhookSignature;

$tablePrefix = env('COMMUNICATIONS_TABLE_PREFIX', '');

return [

    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('COMMUNICATIONS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => [
            'batches' => env('COMMUNICATIONS_TABLE_BATCHES', $tablePrefix.'communication_batches'),
            'threads' => env('COMMUNICATIONS_TABLE_THREADS', $tablePrefix.'communication_threads'),
            'communications' => env('COMMUNICATIONS_TABLE_COMMUNICATIONS', $tablePrefix.'communications'),
            'recipients' => env('COMMUNICATIONS_TABLE_RECIPIENTS', $tablePrefix.'communication_recipients'),
            'contents' => env('COMMUNICATIONS_TABLE_CONTENTS', $tablePrefix.'communication_contents'),
            'deliveries' => env('COMMUNICATIONS_TABLE_DELIVERIES', $tablePrefix.'communication_deliveries'),
            'attempts' => env('COMMUNICATIONS_TABLE_ATTEMPTS', $tablePrefix.'communication_attempts'),
            'events' => env('COMMUNICATIONS_TABLE_EVENTS', $tablePrefix.'communication_events'),
            'templates' => env('COMMUNICATIONS_TABLE_TEMPLATES', $tablePrefix.'communication_templates'),
            'template_versions' => env('COMMUNICATIONS_TABLE_TEMPLATE_VERSIONS', $tablePrefix.'communication_template_versions'),
            'preferences' => env('COMMUNICATIONS_TABLE_PREFERENCES', $tablePrefix.'communication_preferences'),
            'suppressions' => env('COMMUNICATIONS_TABLE_SUPPRESSIONS', $tablePrefix.'communication_suppressions'),
            'attachments' => env('COMMUNICATIONS_TABLE_ATTACHMENTS', $tablePrefix.'communication_attachments'),
            'references' => env('COMMUNICATIONS_TABLE_REFERENCES', $tablePrefix.'communication_references'),
            'tracking_tokens' => env('COMMUNICATIONS_TABLE_TRACKING_TOKENS', $tablePrefix.'communication_tracking_tokens'),
            'notification_inboxes' => env('COMMUNICATIONS_TABLE_NOTIFICATION_INBOXES', $tablePrefix.'notification_inboxes'),
        ],
    ],

    /* Defaults */
    'defaults' => [
        'priority' => env('COMMUNICATIONS_DEFAULT_PRIORITY', 'normal'),
        'max_attempts' => (int) env('COMMUNICATIONS_DEFAULT_MAX_ATTEMPTS', 3),
    ],

    /* Features / Behavior */
    'features' => [
        'owner' => [
            'enabled' => env('COMMUNICATIONS_OWNER_ENABLED', true),
            'include_global' => env('COMMUNICATIONS_OWNER_INCLUDE_GLOBAL', false),
            'auto_assign_on_create' => env('COMMUNICATIONS_OWNER_AUTO_ASSIGN', true),
        ],
        'native_capture' => (bool) env('COMMUNICATIONS_NATIVE_CAPTURE', true),
        'auto_capture' => (bool) env('COMMUNICATIONS_AUTO_CAPTURE', false),
        'auto_capture_allowlist' => [],
        'auto_capture_denylist' => [],
        'auto_capture_ignored_channels' => [],
    ],

    /* Integrations */
    'integrations' => [
        'activitylog' => [
            'enabled' => (bool) env('COMMUNICATIONS_ACTIVITYLOG_ENABLED', false),
        ],
        'laravel_auditing' => [
            'enabled' => (bool) env('COMMUNICATIONS_LARAVEL_AUDITING_ENABLED', false),
        ],
    ],

    /* HTTP */
    'http' => [
        'route_prefix' => env('COMMUNICATIONS_ROUTE_PREFIX', 'communications'),
    ],

    /* Webhooks */
    'webhooks' => [
        'middleware' => ['api', VerifyWebhookSignature::class],
        'providers' => [],
        'route_name_prefix' => env('COMMUNICATIONS_WEBHOOKS_ROUTE_NAME_PREFIX', 'communications.webhooks.'),
    ],

    /* Cache */
    'cache' => [
        'idempotency_store' => env('COMMUNICATIONS_IDEMPOTENCY_STORE', env('CACHE_STORE', 'array')),
        'idempotency_ttl' => (int) env('COMMUNICATIONS_IDEMPOTENCY_TTL', 3600),
    ],

    /* Logging */
    'logging' => [
        'payload_retention_days' => (int) env('COMMUNICATIONS_PAYLOAD_RETENTION_DAYS', 90),
    ],

];
