<?php

declare(strict_types=1);

$tablePrefix = env('CONTACTING_TABLE_PREFIX', '');

return [
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('CONTACTING_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => [
            'contact_methods' => env('CONTACTING_TABLE_CONTACT_METHODS', $tablePrefix.'contact_methods'),
            'social_profiles' => env('CONTACTING_TABLE_SOCIAL_PROFILES', $tablePrefix.'social_profiles'),
            'contact_snapshots' => env('CONTACTING_TABLE_CONTACT_SNAPSHOTS', $tablePrefix.'contact_snapshots'),
        ],
    ],

    'defaults' => [
        'country_code' => env('CONTACTING_DEFAULT_COUNTRY_CODE', 'MY'),
        'public_by_default' => true,
        'verified_by_default' => false,
    ],

    'features' => [
        'owner' => [
            'enabled' => env('CONTACTING_OWNER_ENABLED', true),
            'include_global' => env('CONTACTING_OWNER_INCLUDE_GLOBAL', false),
            'auto_assign_on_create' => env('CONTACTING_OWNER_AUTO_ASSIGN', true),
        ],
        'contact_snapshots' => true,
        'strict_social_platforms' => false,
        'strict_contact_types' => false,
    ],

    'contact_methods' => [
        'types' => [
            'email',
            'phone',
            'mobile',
            'whatsapp',
            'website',
            'telegram',
            'fax',
            'other',
        ],

        'purposes' => [
            'general',
            'admin',
            'support',
            'billing',
            'sales',
            'registration',
            'media',
            'donation',
            'partnership',
            'emergency',
            'privacy',
            'other',
        ],
    ],

    'social_profiles' => [
        'platforms' => [
            'facebook' => ['label' => 'Facebook', 'prefix' => 'www.facebook.com/'],
            'instagram' => ['label' => 'Instagram', 'prefix' => 'www.instagram.com/'],
            'tiktok' => ['label' => 'TikTok', 'prefix' => 'www.tiktok.com/@'],
            'youtube' => ['label' => 'YouTube', 'prefix' => 'www.youtube.com/@'],
            'x' => ['label' => 'X / Twitter', 'prefix' => 'x.com/'],
            'linkedin' => ['label' => 'LinkedIn', 'prefix' => 'www.linkedin.com/in/'],
            'threads' => ['label' => 'Threads', 'prefix' => 'www.threads.net/@'],
            'snapchat' => ['label' => 'Snapchat', 'prefix' => 'www.snapchat.com/add/'],
            'reddit' => ['label' => 'Reddit', 'prefix' => 'www.reddit.com/user/'],
            'pinterest' => ['label' => 'Pinterest', 'prefix' => 'www.pinterest.com/'],
            'discord' => ['label' => 'Discord', 'prefix' => 'discord.gg/'],
            'twitch' => ['label' => 'Twitch', 'prefix' => 'www.twitch.tv/'],
            'bluesky' => ['label' => 'Bluesky', 'prefix' => 'bsky.app/profile/'],
            'mastodon' => ['label' => 'Mastodon', 'prefix' => 'mastodon.social/@'],
            'tumblr' => ['label' => 'Tumblr', 'prefix' => 'www.tumblr.com/'],
            'behance' => ['label' => 'Behance', 'prefix' => 'www.behance.net/'],
            'lemon8' => ['label' => 'Lemon8', 'prefix' => 'www.lemon8-app.com/@'],
            'pinkary' => ['label' => 'Pinkary', 'prefix' => 'pinkary.com/@'],
            'truth_social' => ['label' => 'Truth Social', 'prefix' => 'truthsocial.com/@'],
            'quora' => ['label' => 'Quora', 'prefix' => 'www.quora.com/profile/'],
            'flickr' => ['label' => 'Flickr', 'prefix' => 'www.flickr.com/'],
            'deviantart' => ['label' => 'DeviantArt', 'prefix' => 'www.deviantart.com/'],
            'whatsapp' => ['label' => 'WhatsApp', 'prefix' => 'wa.me/'],
            'telegram' => ['label' => 'Telegram', 'prefix' => 't.me/'],
            'signal' => ['label' => 'Signal'],
            'line' => ['label' => 'LINE', 'prefix' => 'line.me/R/ti/p/'],
            'wechat' => ['label' => 'WeChat'],
            'kakaotalk' => ['label' => 'KakaoTalk'],
            'viber' => ['label' => 'Viber'],
            'medium' => ['label' => 'Medium', 'suffix' => '.medium.com'],
            'substack' => ['label' => 'Substack', 'prefix' => 'substack.com/@'],
            'blogger' => ['label' => 'Blogger', 'suffix' => '.blogspot.com'],
            'wordpress' => ['label' => 'WordPress', 'suffix' => '.wordpress.com'],
            'patreon' => ['label' => 'Patreon', 'prefix' => 'www.patreon.com/'],
            'ko_fi' => ['label' => 'Ko-fi', 'prefix' => 'ko-fi.com/'],
            'buymeacoffee' => ['label' => 'Buy Me a Coffee', 'prefix' => 'buymeacoffee.com/'],
            'github' => ['label' => 'GitHub', 'prefix' => 'github.com/'],
            'gitlab' => ['label' => 'GitLab', 'prefix' => 'gitlab.com/'],
            'vk' => ['label' => 'VK', 'prefix' => 'vk.com/'],
            'weibo' => ['label' => 'Weibo', 'prefix' => 'weibo.com/u/'],
            'douyin' => ['label' => 'Douyin', 'prefix' => 'www.douyin.com/user/'],
            'xiaohongshu' => ['label' => 'Xiaohongshu', 'prefix' => 'www.xiaohongshu.com/user/profile/'],
            'website' => ['label' => 'Website'],
            'other' => ['label' => 'Other'],
        ],
    ],
];
