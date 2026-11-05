<?php

use App\Models\User;
use App\Services\ShareTrackingService;

beforeEach(function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
});

test('dakwah connects sharing invitations and rewards and offers tracked resharing', function (): void {
    $user = User::factory()->create();
    app(ShareTrackingService::class)->attributedUrl($user, route('events.index'), 'Useful events');

    $this->actingAs($user)->get(route('dakwah'))
        ->assertSuccessful()
        ->assertSee(route('dashboard.referrals'), false)
        ->assertSee(route('dashboard.rewards'), false)
        ->assertSee(__('Find a page to share'))
        ->assertSee(__('Share again'))
        ->assertSee('payloadEndpoint', false)
        ->assertSee(__('No recorded shares'));

    $this->get(route('dashboard.referrals'))
        ->assertSuccessful()
        ->assertSee(route('dakwah'), false);
});

test('unconfigured reporting keeps sharing available and withholds measurements on both dakwah pages', function (): void {
    $user = User::factory()->create();
    $link = app(ShareTrackingService::class)->createOrReuseLink($user, route('events.index'), 'Useful events');
    config(['share-tracking.reporting.reporting_start' => null]);

    foreach ([route('dakwah'), route('dakwah.links.show', ['link' => $link->id])] as $url) {
        $this->actingAs($user)->get($url)
            ->assertSuccessful()
            ->assertSee(__('Reporting is not configured yet. Measurements appear after the operator declares a reporting start.'))
            ->assertSee('Useful events')
            ->assertSee('payloadEndpoint', false)
            ->assertDontSee(__('No responses yet'))
            ->assertDontSee(__('Recorded Check-ins'))
            ->assertDontSee(__('By Shared Type'))
            ->assertDontSee(__('Last 14 days'));
    }
});
