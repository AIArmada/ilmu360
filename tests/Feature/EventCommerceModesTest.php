<?php

use AIArmada\Events\Enums\PricingMode;
use App\Support\Commerce\EventCommerceModes;

it('exposes package-native commerce modes including paid and mixed', function () {
    $keys = collect(EventCommerceModes::catalog())->pluck('key')->all();

    expect($keys)
        ->toContain('free_open', 'free_rsvp', 'free_ticketed', 'paid_ticketed', 'mixed_ticketed')
        ->and(collect(EventCommerceModes::catalog())->pluck('pricing_mode')->unique()->all())
        ->toContain(PricingMode::Free->value, PricingMode::Paid->value, PricingMode::Mixed->value);
});

it('defaults public paid checkout off while accepting paid pricing modes', function () {
    expect(EventCommerceModes::publicPaidCheckoutEnabled())->toBeFalse()
        ->and(EventCommerceModes::acceptPaidPricingModes())->toBeTrue()
        ->and(EventCommerceModes::defaultPricingMode())->toBe(PricingMode::Free);
});
