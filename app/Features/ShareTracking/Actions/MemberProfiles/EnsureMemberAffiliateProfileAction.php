<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\MemberProfiles;

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\States\Active;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\ShareTracking\Api\MemberAffiliateProfileResult;
use App\Features\ShareTracking\Api\MemberAffiliateProfileWriter;
use App\Features\ShareTracking\Support\ShareTrackingProfile;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates or refreshes the canonical member share profile.
 *
 * The caller retains owner context and supplies the caller's name/email
 * snapshot; the shared account directory locks the persisted account without
 * exposing its model.
 */
final readonly class EnsureMemberAffiliateProfileAction implements MemberAffiliateProfileWriter
{
    public function __construct(
        private AccountDirectory $accounts,
        private UserMorphType $userMorphType,
    ) {}

    public function ensureForUser(string $userId, string $name, ?string $email): MemberAffiliateProfileResult
    {
        return DB::transaction(function () use ($userId, $name, $email): MemberAffiliateProfileResult {
            $ownerType = $this->userMorphType->morphClass();
            $owner = OwnerContext::resolve();

            if (! $owner instanceof Model
                || $owner->getMorphClass() !== $ownerType
                || (string) $owner->getKey() !== $userId) {
                throw new InvalidArgumentException('Member share profiles require a matching owner context.');
            }

            // Locking the account inside this transaction makes concurrent
            // creators for one account elect one profile.
            $this->accounts->lockForUpdate($userId);

            $affiliate = $this->findAffiliateForOwner($ownerType, $userId);

            if ($affiliate instanceof Affiliate) {
                // A marked but suspended or disabled profile is never
                // reactivated or refreshed through share creation.
                if (! ShareTrackingProfile::isEligibleForNewCredit($affiliate)) {
                    return new MemberAffiliateProfileResult((string) $affiliate->getKey(), false);
                }

                $this->refreshMarkedMemberSnapshot($affiliate, $name, $email);

                return new MemberAffiliateProfileResult((string) $affiliate->getKey(), true);
            }

            return $this->createCanonicalAffiliateForOwner($owner, $ownerType, $userId, $name, $email);
        });
    }

    private function findAffiliateForOwner(string $ownerType, string $ownerId): ?Affiliate
    {
        return Affiliate::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->get()
            ->first(fn (Affiliate $affiliate): bool => ShareTrackingProfile::isMarkedMemberForOwner(
                $affiliate,
                $ownerType,
                $ownerId,
            ));
    }

    private function refreshMarkedMemberSnapshot(Affiliate $affiliate, string $name, ?string $email): void
    {
        // Reuse refreshes the display snapshot and reasserts the member
        // purpose. Eligibility preserves lifecycle state; unrelated metadata
        // stays intact, and callers cannot supply values for these fields.
        $metadata = is_array($affiliate->metadata) ? $affiliate->metadata : [];
        $metadata[ShareTrackingProfile::MARKER_KEY] = true;
        $metadata[ShareTrackingProfile::KIND_KEY] = ShareTrackingProfile::KIND_MEMBER;

        $affiliate->fill([
            'name' => $name,
            'contact_email' => $email,
            'metadata' => $metadata,
            'purpose' => ShareTrackingProfile::PURPOSE_SHARE_MEMBER,
        ]);
        $affiliate->save();
    }

    private function createCanonicalAffiliateForOwner(Model $owner, string $ownerType, string $ownerId, string $name, ?string $email): MemberAffiliateProfileResult
    {
        try {
            // Nested savepoint: a unique collision rolls back to the
            // savepoint only, so the caller's outer transaction stays
            // usable for the requery on real drivers.
            return DB::transaction(function () use ($owner, $ownerType, $ownerId, $name, $email): MemberAffiliateProfileResult {
                $affiliate = new Affiliate([
                    'code' => $this->memberAffiliateCode($ownerType, $ownerId),
                    'name' => $name,
                    'description' => 'ilmu360 share-tracking affiliate profile',
                    'status' => Active::class,
                    'commission_type' => CommissionType::Percentage,
                    'commission_rate' => 0,
                    'currency' => (string) config('affiliates.currency.default', 'MYR'),
                    'contact_email' => $email,
                    'metadata' => ShareTrackingProfile::memberMetadata(),
                    'purpose' => ShareTrackingProfile::PURPOSE_SHARE_MEMBER,
                    'activated_at' => now(),
                ]);

                $affiliate->assignOwner($owner);
                $affiliate->save();

                return new MemberAffiliateProfileResult((string) $affiliate->getKey(), true);
            }, 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // A racing creator won the canonical identity: replay it after
            // rechecking eligibility. Anything else fails explicitly; an
            // unmarked commercial collision is never adopted or suffixed.
            $winner = $this->findAffiliateForOwner($ownerType, $ownerId);

            if (! $winner instanceof Affiliate) {
                throw new InvalidArgumentException('A share profile for this account conflicts with an existing profile identity.', (int) $exception->getCode(), $exception);
            }

            if (! ShareTrackingProfile::isEligibleForNewCredit($winner)) {
                return new MemberAffiliateProfileResult((string) $winner->getKey(), false);
            }

            return new MemberAffiliateProfileResult((string) $winner->getKey(), true);
        }
    }

    private function memberAffiliateCode(string $ownerType, string $ownerId): string
    {
        return 'I360'.Str::upper(substr(hash('sha256', 'share-member-v1|'.$ownerType.'|'.$ownerId), 0, 12));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
