<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\States\Active;
use App\Models\User;

/**
 * Canonical share-profile identity.
 *
 * A share-tracking profile is an Affiliate row explicitly marked through
 * metadata. The marker is the only adoption signal: profile codes, names,
 * descriptions and legacy metadata keys are never read for ownership.
 */
final class ShareTrackingProfile
{
    public const string MARKER_KEY = 'share_tracking_profile';

    public const string KIND_KEY = 'profile_kind';

    public const string KIND_MEMBER = 'member';

    public const string KIND_GUEST = 'guest';

    /**
     * Neutral package purpose values written by this host. The package only
     * tests null/'general' (commercial) versus anything else; these strings
     * are host vocabulary and must stay out of package code.
     */
    public const string PURPOSE_SHARE_MEMBER = 'share_member';

    public const string PURPOSE_SHARE_GUEST = 'share_guest';

    /**
     * @return array<string, mixed>
     */
    public static function memberMetadata(): array
    {
        return [
            self::MARKER_KEY => true,
            self::KIND_KEY => self::KIND_MEMBER,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function guestMetadata(): array
    {
        return [
            self::MARKER_KEY => true,
            self::KIND_KEY => self::KIND_GUEST,
        ];
    }

    public static function isMarked(?Affiliate $affiliate): bool
    {
        if (! $affiliate instanceof Affiliate) {
            return false;
        }

        $metadata = $affiliate->metadata;

        return is_array($metadata)
            && ($metadata[self::MARKER_KEY] ?? null) === true
            && in_array($metadata[self::KIND_KEY] ?? null, [self::KIND_MEMBER, self::KIND_GUEST], true);
    }

    public static function isMarkedMemberFor(?Affiliate $affiliate, User $user): bool
    {
        if (! self::isMarked($affiliate)) {
            return false;
        }

        /** @var Affiliate $affiliate */
        $metadata = $affiliate->metadata;

        return is_array($metadata)
            && ($metadata[self::KIND_KEY] ?? null) === self::KIND_MEMBER
            && $affiliate->owner_type === $user->getMorphClass()
            && (string) $affiliate->owner_id === (string) $user->getKey();
    }

    public static function isMarkedGuest(?Affiliate $affiliate): bool
    {
        if (! self::isMarked($affiliate)) {
            return false;
        }

        /** @var Affiliate $affiliate */
        $metadata = $affiliate->metadata;

        return is_array($metadata)
            && ($metadata[self::KIND_KEY] ?? null) === self::KIND_GUEST
            && $affiliate->owner_type === null
            && $affiliate->owner_id === null;
    }

    /**
     * Eligibility for NEW referral credit only. Historical source-linked
     * facts keep their frozen provenance and are never re-gated here.
     * Orphaned profiles kept for financial settlement cannot earn credit.
     */
    public static function isEligibleForNewCredit(?Affiliate $affiliate): bool
    {
        if (! self::isMarked($affiliate)
            || ! $affiliate->status instanceof Active
            || $affiliate->deactivated_at !== null
            || $affiliate->paused_at !== null) {
            return false;
        }

        /** @var Affiliate $affiliate */
        $metadata = $affiliate->metadata;

        if (($metadata[self::KIND_KEY] ?? null) === self::KIND_GUEST) {
            return $affiliate->owner_type === null && $affiliate->owner_id === null;
        }

        return $affiliate->owner instanceof User;
    }
}
