<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\PaidConversion;
use AIArmada\Affiliates\States\ReversedConversion;
use App\Models\Event;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareTrackingFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP1 Finance Sharer',
        'email' => 'wp1-finance-sharer@example.test',
    ]);

    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
});

function wp1FinanceLinkAndAttribution(TestCase $testCase): array
{
    $service = app(ShareTrackingService::class);
    $service->createOrReuseLink($testCase->sharer, route('events.show', $testCase->event), $testCase->event->title);

    $affiliate = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($testCase->sharer);
    expect($affiliate)->not->toBeNull();

    $link = AffiliateLink::query()->where('affiliate_id', $affiliate->id)->firstOrFail();

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-finance',
        'subject_instance' => 'default',
        'cookie_value' => 'cookie-wp1-finance-'.Str::random(8),
        'landing_url' => route('events.show', $testCase->event),
        'affiliate_link_id' => $link->id,
        'attribution_type' => 'landing',
        'visitor_key' => 'visitor-wp1-finance-'.Str::random(8),
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
    ]);

    return [$affiliate, $link, $attribution];
}

it('reads only canonical paid storage values with no class-name alias', function (): void {
    // Paid is the only monetary settlement storage schema. A bare
    // reversed status is not monetary: zero-value reversed share facts
    // are nonfinancial history with explicit correction evidence, while
    // payout-linked or nonzero reversal legs stay monetary via their
    // money counters.
    expect(ShareTrackingFinance::settledStatusValues())->toBe(['paid']);

    foreach (ShareTrackingFinance::settledStatusValues() as $value) {
        expect($value)->not->toContain('\\');
    }

    expect(ShareTrackingFinance::isSettledStatus('paid'))->toBeTrue()
        ->and(ShareTrackingFinance::isSettledStatus('reversed'))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus(PaidConversion::class))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus(ReversedConversion::class))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus('approved'))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus(''))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus(null))->toBeFalse()
        ->and(ShareTrackingFinance::isSettledStatus(ApprovedConversion::class))->toBeFalse();
});

it('treats typed paid and reversed states as settled at the model boundary', function (): void {
    [$affiliate, $link, $attribution] = wp1FinanceLinkAndAttribution($this);

    $paid = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-finance-paid',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'external_reference' => 'wp1-finance:paid-zero',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => PaidConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHour(),
    ]);

    $reversed = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-finance-reversed',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'external_reference' => 'wp1-finance:reversed-zero',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ReversedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHour(),
    ]);

    $reversedMoney = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_key' => 'event:wp1-finance-reversed-held',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'external_reference' => 'wp1-finance:reversed-held',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 150,
        'commission_currency' => 'MYR',
        'status' => ReversedConversion::class,
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHour(),
    ]);

    // The class name is constructor input; storage stays canonical.
    expect($paid->getAttributes()['status'])->toBe('paid')
        ->and($reversed->getAttributes()['status'])->toBe('reversed');

    $paid->refresh();
    $reversed->refresh();
    $reversedMoney->refresh();

    expect($paid->status)->toBeInstanceOf(PaidConversion::class)
        ->and($reversed->status)->toBeInstanceOf(ReversedConversion::class)
        ->and(ShareTrackingFinance::isSettledStatus($paid->status))->toBeTrue()
        ->and(ShareTrackingFinance::isSettledStatus($reversed->status))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversion($paid))->toBeTrue()
        ->and(ShareTrackingFinance::isMonetaryConversion($reversed))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversion($reversedMoney))->toBeTrue();
});

it('preserves paid and monetary reversed conversions while deleting zero-value reversed facts with correction evidence', function (): void {
    [$affiliate, $link, $attribution] = wp1FinanceLinkAndAttribution($this);

    $base = [
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'affiliate_attribution_id' => $attribution->id,
        'subject_type' => 'event',
        'subject_instance' => 'default',
        'conversion_type' => 'cash_reward',
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'affiliate_link_id' => $link->id,
        'occurred_at' => now()->subHour(),
    ];

    $paid = AffiliateConversion::query()->create([
        ...$base,
        'subject_key' => 'event:wp1-finance-paid',
        'external_reference' => 'wp1-finance:paid-delete',
        'status' => PaidConversion::class,
    ]);

    $reversedMoney = AffiliateConversion::query()->create([
        ...$base,
        'subject_key' => 'event:wp1-finance-reversed-held',
        'external_reference' => 'wp1-finance:reversed-held-delete',
        'commission_minor' => 150,
        'status' => ReversedConversion::class,
    ]);

    $reversedZero = AffiliateConversion::query()->create([
        ...$base,
        'subject_key' => 'event:wp1-finance-reversed',
        'external_reference' => 'wp1-finance:reversed-delete',
        'status' => ReversedConversion::class,
    ]);

    $correction = new ShareOutcomeCorrection;
    $correction->forceFill([
        'conversion_id' => $reversedZero->getKey(),
        'business_key' => 'share-outcome:event_save:bookmark:'.((string) Str::uuid()),
        'reason' => 'wp1 finance probe',
        'occurred_at' => now(),
    ])->save();

    $noise = AffiliateConversion::query()->create([
        ...$base,
        'subject_key' => 'event:wp1-finance-noise',
        'external_reference' => 'wp1-finance:noise-delete',
        'conversion_type' => 'event_save',
        'status' => ApprovedConversion::class,
    ]);

    app(ShareTrackingService::class)->deleteUserTracking($this->sharer);

    $paidAfter = AffiliateConversion::query()->whereKey($paid->id)->firstOrFail();
    $reversedMoneyAfter = AffiliateConversion::query()->whereKey($reversedMoney->id)->firstOrFail();

    expect($paidAfter->getAttributes()['status'])->toBe('paid')
        ->and($reversedMoneyAfter->getAttributes()['status'])->toBe('reversed')
        ->and($paidAfter->commission_minor)->toBe(0)
        ->and($reversedMoneyAfter->commission_minor)->toBe(150)
        ->and($paidAfter->affiliate_payout_id)->toBeNull()
        ->and($reversedMoneyAfter->affiliate_payout_id)->toBeNull()
        ->and($paidAfter->status)->toBeInstanceOf(PaidConversion::class)
        ->and($reversedMoneyAfter->status)->toBeInstanceOf(ReversedConversion::class)
        ->and(AffiliateConversion::query()->whereKey($reversedZero->id)->exists())->toBeFalse()
        ->and(AffiliateConversion::query()->whereKey($noise->id)->exists())->toBeFalse()
        ->and(ShareOutcomeCorrection::query()->whereKey($correction->id)->exists())->toBeTrue();
});

it('judges snapshot rows by canonical values without alias fallback', function (): void {
    $zero = [
        'affiliate_payout_id' => null,
        'commission_minor' => 0,
        'held_minor' => 0,
        'value_minor' => 0,
        'subtotal_minor' => 0,
    ];

    expect(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => 'paid']))->toBeTrue()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => 'reversed']))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => 'reversed', 'held_minor' => 300]))->toBeTrue()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => PaidConversion::class]))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => ReversedConversion::class]))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => 'approved']))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes($zero))->toBeFalse()
        ->and(ShareTrackingFinance::isMonetaryConversionAttributes([...$zero, 'status' => 'approved', 'held_minor' => 300]))->toBeTrue();
});
