<?php

declare(strict_types=1);

namespace App\Features\Rewards\Queries;

use AIArmada\Affiliates\Features\Conversions\Models\AffiliateConversion;
use AIArmada\Affiliates\Features\Conversions\States\ConversionStatus;
use AIArmada\Affiliates\Features\Fraud\Api\FraudSignalStatus;
use AIArmada\Affiliates\Features\Fraud\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Features\Identity\Models\AffiliateClaim;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\ShareTracking\Api\ActiveGuestClaimants;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\OutcomeCorrectionSnapshot;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\SourceAttributionReceiptSnapshot;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Production source-fact adapter over committed share attribution evidence.
 *
 * Read path (all fail-closed, never invented):
 * - key must be canonical `share-outcome:{type}:{transition}` with a known
 *   outcome; anything else is an unknown source (null) and evaluation refuses
 *   it without freezing a row.
 * - the frozen `ShareSourceAttributionReceipt` is the attribution authority;
 *   a missing receipt is null, an unattributed receipt yields a fact with
 *   `isAttributed=false` so policy rejects on merits (frozen) instead of
 *   throwing transiently — an originally unattributed fact can never become
 *   attributed later.
 * - the `AffiliateConversion` (idempotency key `sha256(business key)`) carries
 *   sharer/actor identities, occurrence evidence and status. A missing
 *   conversion under an attributed receipt is null (inconsistent: refuse and
 *   let a later legitimate read decide, never freeze).
 * - guest sharers resolve to their proven claimant through an ACTIVE
 *   `AffiliateClaim` on the conversion affiliate; revoked/expired claims do
 *   not resolve, and guest actors never resolve (no stored actor-affiliate
 *   link), so unclaimed-guest participant rewards stay impossible.
 * - verification is per-outcome evidence: staff check-in needs
 *   `verified_by_user_id`, registration needs an accepted status, submissions
 *   need moderation approval; everything unproven is `Unverified`.
 * - corrections and conversion status drive validity: a
 *   `ShareOutcomeCorrection` row or a reversed/rejected conversion invalidates
 *   the fact with the correction id as evidence.
 * - fraud is the latest package `AffiliateFraudSignal` for the conversion:
 *   confirmed → confirmed, detected/reviewed → suspect, dismissed/none →
 *   clear. Dismissal here only clears the reading; award approval stays a
 *   separate human decision in `DismissRewardFraudAction`.
 */
final class ShareAttributionRewardSourceFactReader implements RewardSourceFactReader
{
    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        $parsed = $this->parse($sourceBusinessKey);

        if ($parsed === null) {
            return null;
        }

        [$outcome, $transitionId] = $parsed;

        $receipt = app(SourceReceiptReader::class)->findByBusinessKey($sourceBusinessKey);

        if (! $receipt instanceof SourceAttributionReceiptSnapshot) {
            return null;
        }

        $conversion = AffiliateConversion::query()
            ->where('idempotency_key', hash('sha256', $sourceBusinessKey))
            ->first();

        if (! $conversion instanceof AffiliateConversion) {
            return null;
        }

        $status = ConversionStatus::normalize($conversion->getAttribute('status'));

        $isCancelled = $status === 'reversed';
        $isValid = $status !== 'reversed' && $status !== 'rejected';

        $correctionStatus = RewardSourceCorrectionStatus::Current;
        $correctionId = null;

        $correction = app(OutcomeCorrections::class)->findByBusinessKey($sourceBusinessKey);

        if ($correction instanceof OutcomeCorrectionSnapshot) {
            $correctionStatus = RewardSourceCorrectionStatus::Withdrawn;
            $correctionId = $correction->id;
            $isValid = false;
        }

        $source = null;

        if ($outcome === 'saved_search_created') {
            $exists = app(SavedSearchReader::class)->exists(substr($transitionId, strlen('saved_search:')));
            $verification = $exists ? RewardVerificationLevel::Accepted : RewardVerificationLevel::Unverified;
            $sourceInvalidates = ['cancelled' => ! $exists, 'invalid' => ! $exists];
        } elseif ($outcome === 'event_submission') {
            $moderationStatus = app(EventSubmissions::class)->moderationStatus(substr($transitionId, strlen('submission:')));
            $verification = $moderationStatus === 'approved'
                ? RewardVerificationLevel::ModeratedApproved
                : RewardVerificationLevel::Unverified;
            $sourceInvalidates = [
                'cancelled' => $moderationStatus === 'rejected',
                'invalid' => $moderationStatus === 'rejected',
            ];
        } else {
            $source = $this->resolveSourceRecord($transitionId);
            $verification = $this->verificationFor($outcome, $source);
            $sourceInvalidates = $this->sourceInvalidates($outcome, $source);
        }

        if ($sourceInvalidates['cancelled']) {
            $isCancelled = true;
        }

        if ($sourceInvalidates['invalid']) {
            $isValid = false;
        }

        $actorId = $this->nullableUuid($conversion->getAttribute('actor_user_id'));
        $sharerId = $this->nullableUuid($conversion->getAttribute('sharer_user_id'));

        $claimedSharerId = null;

        if ($sharerId === null) {
            $claimedSharerId = $this->activeClaimantFor((string) $conversion->getAttribute('affiliate_id'));

            if ($claimedSharerId !== null) {
                $sharerId = $claimedSharerId;
            }
        }

        $occurredAt = CarbonImmutable::parse($receipt->occurredAt)->setTimezone('UTC');

        $metadata = $conversion->getAttribute('metadata');

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: $outcome,
            category: $this->categoryFor($conversion, is_array($metadata) ? $metadata : null),
            occurredAt: $occurredAt,
            verification: $verification,
            isFirstTime: $this->isFirstTime($conversion, $actorId, $occurredAt),
            actorId: $actorId,
            sharerId: $sharerId,
            actorRole: $this->actorRoleFor($outcome, $source),
            isClaimedIdentity: $actorId !== null || $claimedSharerId !== null,
            isAttributed: $receipt->isAttributed(),
            isAmbiguousDevice: false,
            isBot: is_array($metadata) && ($metadata['is_bot'] ?? null) === true,
            isCancelled: $isCancelled,
            isValid: $isValid,
            fraudStatus: $this->fraudStatusFor($conversion),
            correctionStatus: $correctionStatus,
            correctionId: $correctionId,
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parse(string $sourceBusinessKey): ?array
    {
        if ($sourceBusinessKey === '' || mb_strlen($sourceBusinessKey) > 191 || str_contains($sourceBusinessKey, "\0")) {
            return null;
        }

        $parts = explode(':', $sourceBusinessKey, 3);

        if (count($parts) !== 3 || $parts[0] !== ShareOutcomeBusinessKey::NAMESPACE) {
            return null;
        }

        $outcome = $parts[1];

        if ($outcome === '' || ! in_array($outcome, config('rewards.outcomes', []), true)) {
            return null;
        }

        $expectedPrefixes = ShareOutcomeBusinessKey::sourcePrefixes();

        // The host outcome registry shares values with the share outcome
        // vocabulary; match the canonical source prefix for this outcome.
        $prefix = $expectedPrefixes[$outcome] ?? null;

        if (! is_string($prefix) || ! str_starts_with($parts[2], $prefix)) {
            return null;
        }

        return [$outcome, $parts[2]];
    }

    private function resolveSourceRecord(string $transitionId): Model|RegistrationSnapshot|CheckInSnapshot|null
    {
        /** @var array<string, callable(string): mixed> $map */
        $map = [
            'user:' => static fn (string $id): mixed => User::query()->find($id),
            'registration:' => static fn (string $id): mixed => app(RegistrationLookup::class)->findRegistration($id),
            'checkin:' => static fn (string $id): mixed => app(RegistrationLookup::class)->findCheckIn($id),
            'bookmark:' => static fn (string $id): mixed => Bookmark::query()->find($id),
            'response:' => static fn (string $id): mixed => Response::query()->find($id),
            'follow:' => static fn (string $id): mixed => Follow::query()->find($id),
        ];

        foreach ($map as $prefix => $resolve) {
            if (! str_starts_with($transitionId, $prefix)) {
                continue;
            }

            $id = substr($transitionId, strlen($prefix));

            if ($id === '') {
                return null;
            }

            $source = $resolve($id);

            return $source instanceof Model || $source instanceof RegistrationSnapshot || $source instanceof CheckInSnapshot ? $source : null;
        }

        return null;
    }

    private function verificationFor(string $outcome, Model|RegistrationSnapshot|CheckInSnapshot|null $source): RewardVerificationLevel
    {
        if ($outcome === 'event_checkin') {
            if ($source instanceof CheckInSnapshot && $source->verifiedByUserId !== null) {
                return RewardVerificationLevel::StaffVerified;
            }

            return RewardVerificationLevel::Unverified;
        }

        if ($outcome === 'event_registration') {
            if ($source instanceof RegistrationSnapshot && in_array($source->status, ['confirmed', 'checked_in', 'completed'], true)) {
                return RewardVerificationLevel::Accepted;
            }

            return RewardVerificationLevel::Unverified;
        }

        if ($source instanceof Model) {
            return RewardVerificationLevel::Accepted;
        }

        return RewardVerificationLevel::Unverified;
    }

    /**
     * @return array{cancelled: bool, invalid: bool}
     */
    private function sourceInvalidates(string $outcome, Model|RegistrationSnapshot|CheckInSnapshot|null $source): array
    {
        if ($source === null) {
            // Connection facts (save/going/follow) live and die with their
            // source row: an unsave/unfollow deletes it, which must stop new
            // qualification even before a correction row lands.
            if (in_array($outcome, [
                'event_save',
                'event_going',
                'institution_follow',
                'person_follow',
                'series_follow',
                'reference_follow',
                'saved_search_created',
            ], true)) {
                return ['cancelled' => true, 'invalid' => true];
            }

            return ['cancelled' => false, 'invalid' => false];
        }

        if ($source instanceof RegistrationSnapshot && in_array($source->status, ['cancelled', 'rejected', 'expired', 'refunded'], true)) {
            return ['cancelled' => true, 'invalid' => true];
        }

        return ['cancelled' => false, 'invalid' => false];
    }

    private function actorRoleFor(string $outcome, Model|RegistrationSnapshot|CheckInSnapshot|null $source): RewardBeneficiaryRole
    {
        if ($outcome === 'event_registration'
            && $source instanceof RegistrationSnapshot
            && $source->totalAmount > 0
            && $source->paymentStatus === 'paid'
        ) {
            return RewardBeneficiaryRole::Purchaser;
        }

        if (in_array($outcome, [
            'event_registration',
            'event_checkin',
            'event_going',
            'event_submission',
        ], true)) {
            return RewardBeneficiaryRole::Participant;
        }

        return RewardBeneficiaryRole::Recipient;
    }

    private function isFirstTime(AffiliateConversion $conversion, ?string $actorId, CarbonImmutable $occurredAt): bool
    {
        if ($actorId === null) {
            return false;
        }

        return ! AffiliateConversion::query()
            ->where('conversion_type', (string) $conversion->getAttribute('conversion_type'))
            ->where('actor_user_id', $actorId)
            ->whereKeyNot((string) $conversion->getKey())
            ->where('occurred_at', '<', $occurredAt->setTimezone(config('app.timezone', 'UTC')))
            ->exists();
    }

    private function fraudStatusFor(AffiliateConversion $conversion): RewardSourceFraudStatus
    {
        $signal = AffiliateFraudSignal::query()
            ->where('conversion_id', (string) $conversion->getKey())
            ->orderByDesc('detected_at')
            ->first();

        if (! $signal instanceof AffiliateFraudSignal) {
            return RewardSourceFraudStatus::Clear;
        }

        $status = $signal->getAttribute('status');

        $value = $status instanceof FraudSignalStatus ? $status->value : (string) $status;

        return match ($value) {
            'confirmed' => RewardSourceFraudStatus::Confirmed,
            'detected', 'reviewed' => RewardSourceFraudStatus::Suspect,
            default => RewardSourceFraudStatus::Clear,
        };
    }

    private function activeClaimantFor(string $affiliateId): ?string
    {
        return app(ActiveGuestClaimants::class)->activeClaimantIdForAffiliate($affiliateId);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function categoryFor(AffiliateConversion $conversion, ?array $metadata): ?string
    {
        $category = $metadata['category'] ?? null;

        if (is_string($category) && $category !== '') {
            return mb_substr($category, 0, 120);
        }

        $subjectType = $this->nullableString($conversion->getAttribute('subject_type'));

        return $subjectType === null ? null : mb_substr($subjectType, 0, 120);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableUuid(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null || ! preg_match('/^[0-9a-fA-F-]{32,45}$/', $value)) {
            return null;
        }

        return $value;
    }
}
