<?php

use AIArmada\FilamentSignals\Resources\SavedSignalReportResource;
use AIArmada\FilamentSignals\Resources\SignalGoalResource;
use AIArmada\FilamentSignals\Resources\SignalSegmentResource;
use AIArmada\Signals\Models\SavedSignalReport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

it('shows clearer guidance on the signal segment create form', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(SignalSegmentResource::getUrl('create'))
        ->assertSuccessful()
        ->assertSee('Create a reusable audience group for reports, saved filters, and comparisons.')
        ->assertSee('Segment name')
        ->assertSee('Slug / internal key')
        ->assertSee('How rules should match')
        ->assertSee('Match every rule (AND)')
        ->assertSee('Choose a common field or type your own custom properties.* key.')
        ->assertSee('Add rule')
        ->assertSee('properties.checkout.gateway', false)
        ->assertSee('properties.goal_slug', false);
});

it('shows clearer guidance on the signal goal create form', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(SignalGoalResource::getUrl('create'))
        ->assertSuccessful()
        ->assertSee('Define the event that counts as success, then add optional rules to narrow it down.')
        ->assertSee('Website or app')
        ->assertSee('Event name to count')
        ->assertSee('Event category (optional)')
        ->assertSee('Choose a common event name or type the exact event you want this goal to count.')
        ->assertSee('affiliate.conversion.recorded', false)
        ->assertSee('auth.login', false)
        ->assertSee('acquisition', false)
        ->assertSee('Add rule');
});

it('shows clearer wording on the signal segment index page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(SignalSegmentResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Audience Segments')
        ->assertSee('Group visitors or sessions with reusable rules for reports, filters, and comparisons.')
        ->assertSee('Segment name')
        ->assertSee('Internal key')
        ->assertSee('Rule match')
        ->assertSee('Rules')
        ->assertSee('No audience segments yet')
        ->assertSee('Create your first reusable audience group to filter reports and compare visitor behavior.')
        ->assertSee('New audience segment');
});

it('shows clearer wording on the signal goal index page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(SignalGoalResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Goals')
        ->assertSee('Track the events that count as success for dashboards, funnels, and alerts.')
        ->assertSee('Goal name')
        ->assertSee('Type')
        ->assertSee('Event name')
        ->assertSee('Website / app')
        ->assertSee('No goals yet')
        ->assertSee('Create your first success event definition so dashboards and funnels can measure it.')
        ->assertSee('New goal');
});

it('shows clearer guidance on the saved report funnel builder', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $savedReport = SavedSignalReport::withoutEvents(fn (): SavedSignalReport => SavedSignalReport::query()->create([
        'tracked_property_id' => null,
        'signal_segment_id' => null,
        'name' => 'Journey Funnel',
        'slug' => 'journey-funnel',
        'report_type' => 'conversion_funnel',
        'filters' => null,
        'settings' => [
            'funnel_steps' => [],
        ],
        'is_shared' => false,
        'is_active' => true,
        'owner_type' => null,
        'owner_id' => null,
    ]));

    $this->actingAs($administrator)
        ->get(SavedSignalReportResource::getUrl('edit', ['record' => $savedReport]))
        ->assertSuccessful()
        ->assertSee('Build the journey step by step using an existing goal, a page route, a simple event name, or a detailed event rule.')
        ->assertSee('Funnel steps')
        ->assertSee('Each step is one milestone in the journey you want to measure. Leave this empty to use the starter funnel template.')
        ->assertSee('Add step');
});
