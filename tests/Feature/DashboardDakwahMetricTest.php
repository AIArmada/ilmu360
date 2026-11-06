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
        ->assertDontSee(__('Sumbangan Saya'))
        ->assertDontSee(__('Sesi Ilmu Disokong'))
        ->assertDontSee(__('Jumlah Jangkauan'));

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $responseLabel = $xpath->query('//span[normalize-space(.)="'.__('Recorded Responses').'"]/preceding-sibling::span[1]')->item(0);

    expect(trim((string) $responseLabel?->textContent))->toBe('1');
});

test('dashboard withholds dakwah measurements instead of showing zero success claims before reporting is configured', function (): void {
    $user = User::factory()->create();
    config(['share-tracking.reporting.reporting_start' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Measurements withheld'))
        ->assertDontSee(__('Sumbangan Saya'))
        ->assertDontSee(__('How we count'))
        ->assertDontSee(__('Anda telah membantu membuka jalan kepada ilmu sebanyak :count kali.', ['count' => '0']));

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $recordedResponsesValue = $xpath->query('//span[normalize-space(.)="'.__('Recorded Responses').'"]/preceding-sibling::span[1]')->item(0);

    expect($recordedResponsesValue?->textContent)->toContain('—')
        ->and($recordedResponsesValue?->textContent)->not->toContain('0');
});
