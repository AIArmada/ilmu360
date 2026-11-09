<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Models\SignupAttribution;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Convert an owned attribution row to the public Identity value.
 */
final class SignupAttributionSnapshots
{
    public static function fromModel(SignupAttribution $attribution): SignupAttributionSnapshot
    {
        return new SignupAttributionSnapshot(
            id: (string) $attribution->getKey(),
            accountId: (string) $attribution->user_id,
            referrerAccountId: self::nullableString($attribution->referrer_user_id),
            sourceType: self::sourceType($attribution->source_type),
            status: self::status($attribution->status),
            affiliateId: self::nullableString($attribution->affiliate_id),
            affiliateLinkId: self::nullableString($attribution->affiliate_link_id),
            affiliateAttributionId: self::nullableString($attribution->affiliate_attribution_id),
            referralInviteId: self::nullableString($attribution->referral_invite_id),
            inviteeContactHash: self::nullableString($attribution->invitee_contact_hash),
            signedUpAt: self::immutable($attribution->signed_up_at),
            confirmedAt: self::immutable($attribution->confirmed_at),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function sourceType(mixed $value): SignupAttributionSourceType
    {
        return $value instanceof SignupAttributionSourceType
            ? $value
            : SignupAttributionSourceType::from((string) $value);
    }

    private static function status(mixed $value): SignupAttributionStatus
    {
        return $value instanceof SignupAttributionStatus
            ? $value
            : SignupAttributionStatus::from((string) $value);
    }

    private static function immutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
    }
}
