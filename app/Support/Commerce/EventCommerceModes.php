<?php

declare(strict_types=1);

namespace App\Support\Commerce;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Ticketing\Enums\PricingMode;

/**
 * Product catalog of package-native event commerce modes (ADR-013).
 *
 * Public UX may hide paid checkout, but these modes remain valid domain states.
 */
final class EventCommerceModes
{
    /**
     * @return list<array{key: string, pricing_mode: string, registration_mode: string|null, description: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'key' => 'free_open',
                'pricing_mode' => PricingMode::Free->value,
                'registration_mode' => RegistrationMode::None->value,
                'description' => 'Free open / walk-in event with no required registration.',
            ],
            [
                'key' => 'free_rsvp',
                'pricing_mode' => PricingMode::Free->value,
                'registration_mode' => RegistrationMode::Required->value,
                'description' => 'Free event with RSVP/registration required.',
            ],
            [
                'key' => 'free_ticketed',
                'pricing_mode' => PricingMode::Free->value,
                'registration_mode' => RegistrationMode::Required->value,
                'description' => 'Free ticketed event with passes and check-in.',
            ],
            [
                'key' => 'paid_ticketed',
                'pricing_mode' => PricingMode::Paid->value,
                'registration_mode' => RegistrationMode::Required->value,
                'description' => 'Paid ticketed event fulfilled through package cart/order flows.',
            ],
            [
                'key' => 'mixed_ticketed',
                'pricing_mode' => PricingMode::Mixed->value,
                'registration_mode' => RegistrationMode::Required->value,
                'description' => 'Mixed free and paid ticket types on one event.',
            ],
        ];
    }

    public static function publicPaidCheckoutEnabled(): bool
    {
        return (bool) config('events.features.commerce.public_paid_checkout_enabled', false);
    }

    public static function acceptPaidPricingModes(): bool
    {
        return (bool) config('events.features.commerce.accept_paid_pricing_modes', true);
    }

    public static function defaultPricingMode(): PricingMode
    {
        $value = (string) config('events.features.commerce.default_pricing_mode', PricingMode::Free->value);

        return PricingMode::tryFrom($value) ?? PricingMode::Free;
    }
}
