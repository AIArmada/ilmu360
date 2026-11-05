<?php

use App\Models\User;
use App\Services\ShareTrackingService;

test('unconfigured reporting ignores measurement filters supplied in the URL', function (): void {
    $user = User::factory()->create();
    app(ShareTrackingService::class)->createOrReuseLink($user, route('events.index'), 'Prepared useful page');
    config(['share-tracking.reporting.reporting_start' => null]);

    $this->actingAs($user)->get(route('dakwah', ['status' => 'active', 'outcome' => 'signup']))
        ->assertSuccessful()
        ->assertSee('Prepared useful page')
        ->assertSee(__('Measurements withheld'));
});

test('daily breakdown labels retain the viewer local date west of UTC', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(16, 0));
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
    $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
    $link = app(ShareTrackingService::class)->createOrReuseLink($user, route('events.index'), 'Useful page');

    $response = $this->actingAs($user)->get(route('dakwah.links.show', ['link' => $link->id]))->assertSuccessful();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $day = (new DOMXPath($document))->query('//*[@*[name()="wire:key"]="dakwah-day-2026-11-05"]')->item(0);

    expect($day)->not->toBeNull();
    expect($day->textContent)->toContain('5 Nov 2026');
});
