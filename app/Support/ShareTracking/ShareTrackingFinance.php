<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Affiliates\States\PaidConversion;

/**
 * Financial preservation guard for share-tracking cleanup/restoration.
 *
 * A conversion is monetary when it carries settled money history: a payout
 * link, any nonzero money/held counter (including signed reversal legs), or
 * a paid settlement status. Zero-value reversed share facts are canonical
 * nonfinancial history with explicit correction evidence, never automatic
 * monetary liabilities. Monetary rows are never deleted by tracking cleanup
 * and never replayed from snapshots; zero-value share outcomes without
 * payout links remain deletable nonfinancial history.
 */
final class ShareTrackingFinance
{
    /**
     * Canonical persisted settlement values only.
     *
     * StateCaster::set always persists ::getMorphClass(), so paid is the only
     * monetary settlement storage schema. A fully-qualified class name is a
     * legitimate PHP constructor input, never a second stored form, so it
     * must never appear in storage readers or whereIn aliases. Reversed
     * alone is not monetary: zero-value reversed share facts carry explicit
     * correction evidence and remain deletable nonfinancial history, while
     * payout-linked or nonzero reversal legs stay monetary via their money
     * counters.
     *
     * @return list<string>
     */
    public static function settledStatusValues(): array
    {
        return [
            PaidConversion::$name,
        ];
    }

    public static function isSettledStatus(mixed $status): bool
    {
        if ($status instanceof ConversionStatus) {
            return $status instanceof PaidConversion;
        }

        return is_string($status) && in_array($status, self::settledStatusValues(), true);
    }

    public static function isMonetaryConversion(AffiliateConversion $conversion): bool
    {
        if ($conversion->affiliate_payout_id !== null) {
            return true;
        }

        if ((int) $conversion->commission_minor !== 0
            || (int) $conversion->held_minor !== 0
            || (int) $conversion->value_minor !== 0
            || (int) $conversion->subtotal_minor !== 0) {
            return true;
        }

        return self::isSettledStatus($conversion->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function isMonetaryConversionAttributes(array $attributes): bool
    {
        if (($attributes['affiliate_payout_id'] ?? null) !== null) {
            return true;
        }

        foreach (['commission_minor', 'held_minor', 'value_minor', 'subtotal_minor'] as $field) {
            if ((int) ($attributes[$field] ?? 0) !== 0) {
                return true;
            }
        }

        return self::isSettledStatus($attributes['status'] ?? null);
    }
}
