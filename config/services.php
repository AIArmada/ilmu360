<?php

declare(strict_types=1);

$githubIssueModelFallbacks = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('GITHUB_ISSUE_REPORTING_ADMIN_MODEL_FALLBACKS', 'GPT-5.2-Codex,Auto')),
), static fn (string $value): bool => $value !== ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/google/callback'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
        'places_enabled' => (bool) env('GOOGLE_PLACES_ENABLED', false),
        'places_server_api_key' => env('GOOGLE_PLACES_SERVER_API_KEY'),
        'place_link_resolution_enabled' => (bool) env('GOOGLE_PLACE_LINK_RESOLUTION_ENABLED', false),
    ],

    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    'github' => [
        'issues' => [
            'enabled' => (bool) env('GITHUB_ISSUE_REPORTING_ENABLED', false),
            'token' => env('GITHUB_ISSUE_REPORTING_TOKEN'),
            'api_base' => env('GITHUB_ISSUE_REPORTING_API_BASE', 'https://api.github.com'),
            'api_version' => env('GITHUB_ISSUE_REPORTING_API_VERSION', '2026-03-10'),
            'repository_owner' => env('GITHUB_ISSUE_REPORTING_REPOSITORY_OWNER', 'AIArmada'),
            'repository_name' => env('GITHUB_ISSUE_REPORTING_REPOSITORY_NAME', 'ilmu360'),
            'base_branch' => env('GITHUB_ISSUE_REPORTING_BASE_BRANCH', 'main'),
            'custom_agent' => env('GITHUB_ISSUE_REPORTING_CUSTOM_AGENT'),
            'custom_instructions' => env('GITHUB_ISSUE_REPORTING_CUSTOM_INSTRUCTIONS'),
            'admin_model' => env('GITHUB_ISSUE_REPORTING_ADMIN_MODEL', 'GPT-5.4'),
            'admin_model_fallbacks' => $githubIssueModelFallbacks,
            'admin_copilot_assignment_enabled' => (bool) env('GITHUB_ISSUE_REPORTING_ADMIN_COPILOT_ASSIGNMENT_ENABLED', true),
            'copilot_assignee' => env('GITHUB_ISSUE_REPORTING_COPILOT_ASSIGNEE', 'copilot-swe-agent[bot]'),
        ],
    ],

];
