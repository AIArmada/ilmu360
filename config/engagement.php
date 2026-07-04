<?php

declare(strict_types=1);
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Reaction;
use AIArmada\Engagement\Models\Reminder;
use AIArmada\Engagement\Models\Response;
use AIArmada\Engagement\Models\Share;
use AIArmada\Engagement\Models\Subscription;
use AIArmada\Engagement\Notifications\EngagementReminderNotification;

$tablePrefix = env('ENGAGEMENT_TABLE_PREFIX', 'engagement_');

return [

    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('ENGAGEMENT_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => [
            'follows' => env('ENGAGEMENT_TABLE_FOLLOWS', $tablePrefix.'follows'),
            'bookmarks' => env('ENGAGEMENT_TABLE_BOOKMARKS', $tablePrefix.'bookmarks'),
            'bookmark_collections' => env('ENGAGEMENT_TABLE_BOOKMARK_COLLECTIONS', $tablePrefix.'bookmark_collections'),
            'bookmark_collection_items' => env('ENGAGEMENT_TABLE_BOOKMARK_COLLECTION_ITEMS', $tablePrefix.'bookmark_collection_items'),
            'responses' => env('ENGAGEMENT_TABLE_RESPONSES', $tablePrefix.'responses'),
            'reactions' => env('ENGAGEMENT_TABLE_REACTIONS', $tablePrefix.'reactions'),
            'subscriptions' => env('ENGAGEMENT_TABLE_SUBSCRIPTIONS', $tablePrefix.'subscriptions'),
            'reminders' => env('ENGAGEMENT_TABLE_REMINDERS', $tablePrefix.'reminders'),
            'shares' => env('ENGAGEMENT_TABLE_SHARES', $tablePrefix.'shares'),
            'engagement_counters' => env('ENGAGEMENT_TABLE_COUNTERS', $tablePrefix.'engagement_counters'),
        ],
    ],

    /* Defaults */
    'defaults' => [
        'follow_notification_level' => env('ENGAGEMENT_DEFAULT_FOLLOW_NOTIFICATION_LEVEL', 'all'),
        'response_visibility' => env('ENGAGEMENT_DEFAULT_RESPONSE_VISIBILITY', 'public'),
    ],

    /* Owner */
    'owner' => [
        'enabled' => env('ENGAGEMENT_OWNER_ENABLED', true),
        'include_global' => env('ENGAGEMENT_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('ENGAGEMENT_OWNER_AUTO_ASSIGN', true),
    ],

    /* Reminder */
    'reminder' => [
        'batch_size' => (int) env('ENGAGEMENT_REMINDER_BATCH_SIZE', 100),
        'default_channels' => ['mail', 'database'],
    ],

    /* Notifications */
    'notifications' => [
        'reminder' => env('ENGAGEMENT_NOTIFICATION_REMINDER_CLASS', EngagementReminderNotification::class),
    ],

    /* Model class overrides */
    'models' => [
        'follow' => Follow::class,
        'bookmark' => Bookmark::class,
        'response' => Response::class,
        'reaction' => Reaction::class,
        'subscription' => Subscription::class,
        'reminder' => Reminder::class,
        'share' => Share::class,
    ],
];
