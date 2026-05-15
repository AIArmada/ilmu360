<?php

use AIArmada\Signals\Models\SavedSignalReport;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalGoal;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\Services\ConversionFunnelReportService;

it('stores session duration in milliseconds while preserving whole seconds', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    $this->postJson('/api/signals/collect/pageview', [
        'write_key' => $trackedProperty->write_key,
        'session_identifier' => 'session-ms-precision',
        'session_started_at' => '2026-03-25T12:00:00.000Z',
        'occurred_at' => '2026-03-25T12:00:00.673Z',
        'path' => '/majlis',
        'url' => url('/majlis'),
        'title' => 'Majlis',
    ])->assertAccepted();

    $session = SignalSession::query()
        ->where('tracked_property_id', $trackedProperty->id)
        ->where('session_identifier', 'session-ms-precision')
        ->first();

    expect($session)->not->toBeNull()
        ->and($session?->duration_milliseconds)->toBe(673)
        ->and(intdiv((int) ($session?->duration_milliseconds ?? 0), 1000))->toBe(0);
});

it('supports goal-based funnel steps via goal slugs', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    $session = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'goal-funnel-session',
        'started_at' => now()->subMinute(),
        'ended_at' => now(),
        'duration_milliseconds' => 12_450,
        'entry_path' => '/',
        'exit_path' => '/majlis/test-event',
        'is_bounce' => false,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(50),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(40),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/majlis/test-event',
        'url' => url('/majlis/test-event'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalGoal::withoutEvents(function () use ($trackedProperty): void {
        SignalGoal::query()->create([
            'tracked_property_id' => $trackedProperty->id,
            'name' => 'Homepage Goal',
            'slug' => 'homepage-goal',
            'goal_type' => 'engagement',
            'event_name' => 'page_view',
            'event_category' => 'page_view',
            'conditions' => [
                ['field' => 'path', 'operator' => 'equals', 'value' => '/'],
            ],
            'is_active' => true,
            'owner_type' => null,
            'owner_id' => null,
        ]);

        SignalGoal::query()->create([
            'tracked_property_id' => $trackedProperty->id,
            'name' => 'Detail Goal',
            'slug' => 'detail-goal',
            'goal_type' => 'conversion',
            'event_name' => 'page_view',
            'event_category' => 'page_view',
            'conditions' => [
                ['field' => 'path', 'operator' => 'starts_with', 'value' => '/majlis/'],
            ],
            'is_active' => true,
            'owner_type' => null,
            'owner_id' => null,
        ]);
    });

    $savedReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'Goal Funnel',
        'slug' => 'goal-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Homepage Goal', 'goal_slug' => 'homepage-goal'],
                ['label' => 'Detail Goal', 'goal_slug' => 'detail-goal'],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $stages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $savedReport->id,
    );

    expect($stages)->toHaveCount(2)
        ->and($stages[0]['count'])->toBe(1)
        ->and($stages[1]['count'])->toBe(1);
});

it('does not fall back to the starter funnel when only tracked-property-compatible goals resolve', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    $otherTrackedProperty = TrackedProperty::query()->whereKeyNot($trackedProperty->id)->firstOrFail();

    $session = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'scoped-goal-funnel-session',
        'started_at' => now()->subMinute(),
        'ended_at' => now(),
        'duration_milliseconds' => 9_100,
        'entry_path' => '/',
        'exit_path' => '/',
        'is_bounce' => false,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(25),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalGoal::withoutEvents(function () use ($trackedProperty, $otherTrackedProperty): void {
        SignalGoal::query()->create([
            'tracked_property_id' => $trackedProperty->id,
            'name' => 'Compatible Goal',
            'slug' => 'compatible-goal',
            'goal_type' => 'engagement',
            'event_name' => 'page_view',
            'event_category' => 'page_view',
            'conditions' => [
                ['field' => 'path', 'operator' => 'equals', 'value' => '/'],
            ],
            'is_active' => true,
            'owner_type' => null,
            'owner_id' => null,
        ]);

        SignalGoal::query()->create([
            'tracked_property_id' => $otherTrackedProperty->id,
            'name' => 'Foreign Goal',
            'slug' => 'foreign-goal',
            'goal_type' => 'conversion',
            'event_name' => 'page_view',
            'event_category' => 'page_view',
            'conditions' => [
                ['field' => 'path', 'operator' => 'equals', 'value' => '/foreign'],
            ],
            'is_active' => true,
            'owner_type' => null,
            'owner_id' => null,
        ]);
    });

    $savedReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'Scoped Goal Funnel',
        'slug' => 'scoped-goal-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Compatible Goal', 'goal_slug' => 'compatible-goal'],
                ['label' => 'Foreign Goal', 'goal_slug' => 'foreign-goal'],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $stages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $savedReport->id,
    );

    expect($stages)->toHaveCount(1)
        ->and($stages[0]['label'])->toBe('Compatible Goal')
        ->and($stages[0]['count'])->toBe(1);
});

it('supports direct page-path funnel steps without separate goals', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    $session = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'path-funnel-session',
        'started_at' => now()->subMinute(),
        'ended_at' => now(),
        'duration_milliseconds' => 8_920,
        'entry_path' => '/',
        'exit_path' => '/penceramah',
        'is_bounce' => false,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(30),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(20),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/penceramah',
        'url' => url('/penceramah'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    $savedReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'Path Funnel',
        'slug' => 'path-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Homepage', 'path_operator' => 'equals', 'path_value' => '/'],
                ['label' => 'Speaker Directory', 'path_operator' => 'equals', 'path_value' => '/penceramah'],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $stages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $savedReport->id,
    );

    expect($stages)->toHaveCount(2)
        ->and($stages[0]['event_name'])->toBe('page_view')
        ->and($stages[0]['count'])->toBe(1)
        ->and($stages[1]['count'])->toBe(1);
});

it('supports route-based funnel steps using named laravel routes', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    $session = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'route-funnel-session',
        'started_at' => now()->subMinute(),
        'ended_at' => now(),
        'duration_milliseconds' => 9_150,
        'entry_path' => '/',
        'exit_path' => '/penceramah',
        'is_bounce' => false,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(25),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $session->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(15),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/penceramah',
        'url' => url('/penceramah'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    $savedReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'Route Funnel',
        'slug' => 'route-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Home Route', 'step_type' => 'route', 'route_name' => 'home'],
                ['label' => 'Speakers Route', 'step_type' => 'route', 'route_name' => 'speakers.index'],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $stages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $savedReport->id,
    );

    expect($stages)->toHaveCount(2)
        ->and($stages[0]['event_name'])->toBe('page_view')
        ->and($stages[0]['count'])->toBe(1)
        ->and($stages[1]['count'])->toBe(1);
});

it('supports any and all funnel condition match types', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    $firstSession = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'condition-funnel-session-a',
        'started_at' => now()->subMinutes(2),
        'ended_at' => now()->subMinute(),
        'duration_milliseconds' => 14_240,
        'entry_path' => '/',
        'exit_path' => '/checkout/start',
        'is_bounce' => false,
    ]);

    $secondSession = SignalSession::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'session_identifier' => 'condition-funnel-session-b',
        'started_at' => now()->subMinutes(2),
        'ended_at' => now()->subMinute(),
        'duration_milliseconds' => 11_640,
        'entry_path' => '/',
        'exit_path' => '/billing/review',
        'is_bounce' => false,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $firstSession->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(80),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $firstSession->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(70),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/checkout/start',
        'url' => url('/checkout/start'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => ['checkout_step' => 'billing'],
        'property_types' => ['checkout_step' => 'string'],
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $secondSession->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(60),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => $secondSession->id,
        'signal_identity_id' => null,
        'occurred_at' => now()->subSeconds(50),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/billing/review',
        'url' => url('/billing/review'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => ['checkout_step' => 'shipping'],
        'property_types' => ['checkout_step' => 'string'],
    ]);

    $allConditionsReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'All Conditions Funnel',
        'slug' => 'all-conditions-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Home', 'step_type' => 'route', 'route_name' => 'home'],
                [
                    'label' => 'Strict Checkout Step',
                    'step_type' => 'conditions',
                    'event_name' => 'page_view',
                    'event_category' => 'page_view',
                    'condition_match_type' => 'all',
                    'conditions' => [
                        ['field' => 'path', 'operator' => 'starts_with', 'value' => '/checkout'],
                        ['field' => 'properties.checkout_step', 'operator' => 'equals', 'value' => 'shipping'],
                    ],
                ],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $anyConditionsReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_segment_id' => null,
        'name' => 'Any Conditions Funnel',
        'slug' => 'any-conditions-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [
                ['label' => 'Home', 'step_type' => 'route', 'route_name' => 'home'],
                [
                    'label' => 'Flexible Checkout Step',
                    'step_type' => 'conditions',
                    'event_name' => 'page_view',
                    'event_category' => 'page_view',
                    'condition_match_type' => 'any',
                    'conditions' => [
                        ['field' => 'path', 'operator' => 'starts_with', 'value' => '/checkout'],
                        ['field' => 'properties.checkout_step', 'operator' => 'equals', 'value' => 'shipping'],
                    ],
                ],
            ],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $allStages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $allConditionsReport->id,
    );

    $anyStages = app(ConversionFunnelReportService::class)->stages(
        $trackedProperty->id,
        null,
        null,
        null,
        $anyConditionsReport->id,
    );

    expect($allStages)->toHaveCount(2)
        ->and($allStages[0]['count'])->toBe(2)
        ->and($allStages[1]['count'])->toBe(0)
        ->and($anyStages)->toHaveCount(2)
        ->and($anyStages[0]['count'])->toBe(2)
        ->and($anyStages[1]['count'])->toBe(2);
});
