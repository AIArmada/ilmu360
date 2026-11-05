<?php

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ApprovedConversion;
use App\Models\User;
use App\Services\ShareTrackingService;

beforeEach(function (): void {
    config(['share-tracking.reporting.reporting_start' => '2026-10-01']);
});

test('dashboard presents an attributed follow as a recorded response rather than a supported session', function (): void {
    $user = User::factory()->create();
    $linkData = app(ShareTrackingService::class)->createOrReuseLink($user, route('institutions.index'), 'Institutions');
    $link = AffiliateLink::query()->findOrFail($linkData->id);
    AffiliateConversion::query()->create([
        'affiliate_id' => $link->affiliate_id,
        'affiliate_code' => $link->affiliate->code,
        'affiliate_link_id' => $link->id,
        'conversion_type' => 'institution_follow',
        'external_reference' => 'institution_follow:dashboard-metric',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Recorded Responses'))
        ->assertSee(__('Visits'))
        ->assertSee(__('Signups'))
        ->assertSee(__('Distinct Browsers'))
        ->assertDontSee(__('Sesi Ilmu Disokong'))
        ->assertDontSee(__('Jumlah Jangkauan'));

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $responseLabel = $xpath->query('//p[normalize-space(.)="'.__('Recorded Responses').'"]/preceding-sibling::p[1]')->item(0);

    expect($responseLabel?->textContent)->toBe('1');
});

test('dashboard withholds dakwah measurements instead of showing zero success claims before reporting is configured', function (): void {
    $user = User::factory()->create();
    config(['share-tracking.reporting.reporting_start' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Measurements withheld'))
        ->assertSee(__('Reporting is not configured yet. Measurements appear after the operator declares a reporting start.'))
        ->assertDontSee(__('Anda telah membantu membuka jalan kepada ilmu sebanyak :count kali.', ['count' => '0']));

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $section = $xpath->query('//h2[normalize-space(.)="'.__('Sumbangan Saya').'"]/ancestor::section[1]')->item(0);

    expect($section)->not->toBeNull();
    expect($section?->textContent)->not->toMatch('/\b0\b/u');
    expect($section?->textContent)->not->toContain(__('Aktif'));
    expect($section?->textContent)->toContain(__('How we count'));
});
