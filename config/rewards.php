<?php

declare(strict_types=1);

$tablePrefix = env('REWARDS_TABLE_PREFIX', 'reward_');

return [

    /* Database */
    'database' => [
        'json_column_type' => env('REWARDS_JSON_COLUMN_TYPE', 'jsonb'),

        'tables' => [
            'programs' => env('REWARDS_TABLE_PROGRAMS', $tablePrefix.'programs'),
            'program_versions' => env('REWARDS_TABLE_PROGRAM_VERSIONS', $tablePrefix.'program_versions'),
            'version_publications' => env('REWARDS_TABLE_VERSION_PUBLICATIONS', $tablePrefix.'version_publications'),
            'enrollments' => env('REWARDS_TABLE_ENROLLMENTS', $tablePrefix.'enrollments'),
            'enrollment_acceptances' => env('REWARDS_TABLE_ENROLLMENT_ACCEPTANCES', $tablePrefix.'enrollment_acceptances'),
            'enrollment_periods' => env('REWARDS_TABLE_ENROLLMENT_PERIODS', $tablePrefix.'enrollment_periods'),
            'source_component_claims' => env('REWARDS_TABLE_SOURCE_COMPONENT_CLAIMS', $tablePrefix.'source_component_claims'),
            'awards' => env('REWARDS_TABLE_AWARDS', $tablePrefix.'awards'),
            'award_entries' => env('REWARDS_TABLE_AWARD_ENTRIES', $tablePrefix.'award_entries'),
            'point_lots' => env('REWARDS_TABLE_POINT_LOTS', $tablePrefix.'point_lots'),
            'point_movements' => env('REWARDS_TABLE_POINT_MOVEMENTS', $tablePrefix.'point_movements'),
            'badge_grants' => env('REWARDS_TABLE_BADGE_GRANTS', $tablePrefix.'badge_grants'),
            'milestone_cycles' => env('REWARDS_TABLE_MILESTONE_CYCLES', $tablePrefix.'milestone_cycles'),
            'milestone_contributions' => env('REWARDS_TABLE_MILESTONE_CONTRIBUTIONS', $tablePrefix.'milestone_contributions'),
            'cap_counters' => env('REWARDS_TABLE_CAP_COUNTERS', $tablePrefix.'cap_counters'),
            'appeals' => env('REWARDS_TABLE_APPEALS', $tablePrefix.'appeals'),
            'appeal_decisions' => env('REWARDS_TABLE_APPEAL_DECISIONS', $tablePrefix.'appeal_decisions'),
            'adjustments' => env('REWARDS_TABLE_ADJUSTMENTS', $tablePrefix.'adjustments'),
            'source_coordinators' => env('REWARDS_TABLE_SOURCE_COORDINATORS', $tablePrefix.'source_coordinators'),
            'point_operations' => env('REWARDS_TABLE_POINT_OPERATIONS', $tablePrefix.'point_operations'),
            'point_reservation_submissions' => env('REWARDS_TABLE_POINT_RESERVATION_SUBMISSIONS', $tablePrefix.'point_reservation_submissions'),
            'funding_entries' => env('REWARDS_TABLE_FUNDING_ENTRIES', $tablePrefix.'funding_entries'),
            'budget_movements' => env('REWARDS_TABLE_BUDGET_MOVEMENTS', $tablePrefix.'budget_movements'),
            'payees' => env('REWARDS_TABLE_PAYEES', $tablePrefix.'payees'),
            'payout_requests' => env('REWARDS_TABLE_PAYOUT_REQUESTS', $tablePrefix.'payout_requests'),
            'funding_sources' => env('REWARDS_TABLE_FUNDING_SOURCES', $tablePrefix.'funding_sources'),
            'benefit_items' => env('REWARDS_TABLE_BENEFIT_ITEMS', $tablePrefix.'benefit_items'),
            'benefit_stock_movements' => env('REWARDS_TABLE_BENEFIT_STOCK_MOVEMENTS', $tablePrefix.'benefit_stock_movements'),
            'benefit_grants' => env('REWARDS_TABLE_BENEFIT_GRANTS', $tablePrefix.'benefit_grants'),
            'recoveries' => env('REWARDS_TABLE_RECOVERIES', $tablePrefix.'recoveries'),
        ],
    ],

    /* Ownership */
    'owner' => [
        'enabled' => (bool) env('REWARDS_OWNER_ENABLED', false),
        'include_global' => (bool) env('REWARDS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => (bool) env('REWARDS_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    /* Defaults */
    'currencies' => ['MYR', 'USD', 'SGD', 'IDR', 'THB', 'GBP', 'EUR', 'AUD'],

    'limits' => [
        'max_points' => 1000000,
        'max_minor_units' => 999999999999,
    ],

    /* Features */
    'outcomes' => [
        'event_checkin',
        'event_submission',
        'signup',
        'event_registration',
        'event_save',
        'event_going',
        'institution_follow',
        'person_follow',
        'series_follow',
        'reference_follow',
        'saved_search_created',
    ],

    'moderated_outcomes' => ['event_submission'],

    'recipient_types' => ['user'],

    'cap_scopes' => [
        'recipient_source_component',
        'recipient_program_day',
        'recipient_program_month',
        'program_day',
        'program_total',
        'family_source_component',
    ],

    'payouts' => [
        'methods' => ['manual'],
    ],

];
