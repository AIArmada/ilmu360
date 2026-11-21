<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\AccountLifecycle;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Features\ShareTracking\Services\AffiliatesShareTrackingService;
use App\Features\ShareTracking\Support\ShareTrackingFinance;
use App\Features\ShareTracking\Support\ShareTrackingProfile;
use App\Models\User;
use App\Shared\Contracts\AffiliateSnapshotPersistence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class ManageShareTrackingAccountLifecycleAction
{
    public function __construct(
        private AffiliatesShareTrackingService $shareTracking,
        private AffiliateSnapshotPersistence $snapshots,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(string $accountId): array
    {
        $user = User::query()->findOrFail($accountId);

        return OwnerContext::withOwner($user, function () use ($user): array {
            // Canonical owned share records only. An unmarked commercial
            // profile is never snapshotted, and financial rows are preserved
            // by non-deletion rather than snapshot replay.
            $affiliate = Affiliate::query()
                ->where('owner_type', $user->getMorphClass())
                ->where('owner_id', $user->getKey())
                ->get()
                ->first(fn (Affiliate $candidate): bool => ShareTrackingProfile::isMarkedMemberForOwner(
                    $candidate,
                    $user->getMorphClass(),
                    (string) $user->getKey(),
                ));

            if (! $affiliate instanceof Affiliate) {
                return [];
            }

            return [
                'affiliate' => $affiliate->getAttributes(),
                'links' => $affiliate->links()->get()->map(fn (AffiliateLink $link): array => $link->getAttributes())->all(),
                'attributions' => $affiliate->attributions()->get()->map(fn (AffiliateAttribution $attribution): array => $attribution->getAttributes())->all(),
                'touchpoints' => AffiliateTouchpoint::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->get()
                    ->map(fn (AffiliateTouchpoint $touchpoint): array => $touchpoint->getAttributes())
                    ->all(),
                'conversions' => $affiliate->conversions()->get()->map(fn (AffiliateConversion $conversion): array => $conversion->getAttributes())->all(),
            ];
        });
    }

    public function detach(string $accountId): void
    {
        $this->shareTracking->deleteUserTracking(User::query()->findOrFail($accountId));
    }

    /** @param array<string, mixed> $record */
    public function restore(string $accountId, array $record): void
    {
        $user = User::query()->findOrFail($accountId);

        OwnerContext::withOwner($user, function () use ($record, $user): void {
            $affiliateAttributes = $record['affiliate'] ?? null;

            if (! is_array($affiliateAttributes)) {
                return;
            }

            // Marker plus owner match, validated before any write. A foreign
            // or tampered snapshot is skipped, never force-assigned.
            if (! $this->snapshotAffiliateBelongsToRestoredUser($user, $affiliateAttributes)) {
                return;
            }

            $affiliate = $this->restoreShareAffiliate($user, $affiliateAttributes);

            if (! $affiliate instanceof Affiliate) {
                return;
            }

            $affiliateId = (string) $affiliate->getKey();

            $this->restoreShareChildren(AffiliateLink::class, $this->snapshotRowsForAffiliate($record['links'] ?? [], $affiliateId));
            $this->restoreShareChildren(AffiliateAttribution::class, $this->snapshotRowsForAffiliate($record['attributions'] ?? [], $affiliateId));
            $this->restoreShareChildren(AffiliateTouchpoint::class, $this->snapshotRowsForAffiliate($record['touchpoints'] ?? [], $affiliateId));
            $this->restoreShareChildren(AffiliateConversion::class, $this->snapshotRowsForAffiliate($record['conversions'] ?? [], $affiliateId));
        });
    }

    /**
     * @param  array<string, mixed>  $affiliateAttributes
     */
    private function snapshotAffiliateBelongsToRestoredUser(User $user, array $affiliateAttributes): bool
    {
        if (($affiliateAttributes['owner_type'] ?? null) !== $user->getMorphClass()
            || (string) ($affiliateAttributes['owner_id'] ?? '') !== (string) $user->getKey()) {
            return false;
        }

        $metadata = $affiliateAttributes['metadata'] ?? null;

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return is_array($metadata)
            && ($metadata[ShareTrackingProfile::MARKER_KEY] ?? null) === true
            && ($metadata[ShareTrackingProfile::KIND_KEY] ?? null) === ShareTrackingProfile::KIND_MEMBER;
    }

    /**
     * @param  array<int, mixed>  $records
     * @return list<array<string, mixed>>
     */
    private function snapshotRowsForAffiliate(array $records, string $affiliateId): array
    {
        return collect($records)
            ->filter(fn (mixed $row): bool => is_array($row)
                && (string) ($row['affiliate_id'] ?? '') === $affiliateId)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function restoreShareAffiliate(User $user, array $attributes): ?Affiliate
    {
        $primaryKey = (new Affiliate)->getKeyName();
        $existing = Affiliate::query()->withoutGlobalScope(OwnerScope::class)->find($attributes[$primaryKey] ?? null);

        if ($existing instanceof Affiliate) {
            // A retained live finance/profile row is never overwritten from
            // a stale snapshot. It must still be the restored user's own
            // marked profile; a foreign row under this id is left untouched
            // and the share restore stops.
            if (! ShareTrackingProfile::isMarkedMemberForOwner(
                $existing,
                $user->getMorphClass(),
                (string) $user->getKey(),
            )) {
                return null;
            }

            return $existing;
        }

        // A missing profile is recreated disabled: no automatic reactivation
        // of referral tracking until an explicit authorized fresh activation.
        $attributes['deactivated_at'] ??= now()->toDateTimeString();

        $restoredId = $this->snapshots->restoreProfile($attributes);

        return Affiliate::query()->withoutGlobalScope(OwnerScope::class)->find($restoredId);
    }

    /**
     * @param  class-string<AffiliateLink|AffiliateAttribution|AffiliateTouchpoint|AffiliateConversion>  $modelClass
     * @param  array<int, mixed>  $records
     */
    private function restoreShareChildren(string $modelClass, array $records): void
    {
        $primaryKey = (new $modelClass)->getKeyName();

        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            // Monetary conversions are preserved by non-deletion, never by
            // snapshot replay. A snapshot copy is skipped even when missing.
            if ($modelClass === AffiliateConversion::class
                && ShareTrackingFinance::isMonetaryConversionAttributes($attributes)) {
                continue;
            }

            $id = $attributes[$primaryKey] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $existing = $modelClass::query()->withoutGlobalScope(OwnerScope::class)->find($id);

            if ($existing instanceof Model) {
                // Never overwrite retained current state from an old
                // snapshot, and never reassociate a foreign existing row:
                // an existing id always wins over the snapshot copy.
                continue;
            }

            $kind = match ($modelClass) {
                AffiliateLink::class => 'link',
                AffiliateAttribution::class => 'attribution',
                AffiliateTouchpoint::class => 'touchpoint',
                AffiliateConversion::class => 'conversion',
                default => throw new \LogicException('Unexpected affiliate snapshot model.'),
            };

            $this->snapshots->restoreChild($kind, $this->sanitizeShareSnapshotAttributes($modelClass, $attributes));
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitizeShareSnapshotAttributes(string $modelClass, array $attributes): array
    {
        if ($modelClass === AffiliateLink::class) {
            // Restored links stay disabled: the public token survives as an
            // identifier but earns nothing until a fresh activation.
            $attributes['deactivated_at'] ??= now()->toDateTimeString();
        }

        if ($modelClass === AffiliateAttribution::class) {
            // Expired/deleted private cookie capabilities are never restored
            // as usable capabilities: a missing or future expiry is clamped
            // to now while the historical ids/evidence are retained.
            $expiresAt = $attributes['expires_at'] ?? null;
            $isUsable = $expiresAt === null
                || ($expiresAt instanceof CarbonInterface && $expiresAt->isFuture())
                || (is_string($expiresAt) && strtotime($expiresAt) > time());

            if ($isUsable) {
                $attributes['expires_at'] = now()->toDateTimeString();
            }
        }

        return $attributes;
    }
}
