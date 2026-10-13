<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\ShareGuestClaim;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
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
 *   `ShareGuestClaim` on the conversion affiliate; revoked/expired claims do
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

        $receipt = ShareSourceAttributionReceipt::query()
            ->where('business_key', $sourceBusinessKey)
            ->first();

        if (! $receipt instanceof ShareSourceAttributionReceipt) {
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

        $correction = ShareOutcomeCorrection::query()
            ->where('business_key', $sourceBusinessKey)
            ->first();

        if ($correction instanceof ShareOutcomeCorrection) {
            $correctionStatus = RewardSourceCorrectionStatus::Withdrawn;
            $correctionId = (string) $correction->getKey();
            $isValid = false;
        }

        $source = $this->resolveSourceRecord($transitionId);

        $verification = $this->verificationFor($outcome, $source);
        $sourceInvalidates = $this->sourceInvalidates($outcome, $source);

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

        $occurredAt = $receipt->getAttribute('occurred_at') instanceof CarbonImmutable
            ? $receipt->getAttribute('occurred_at')->setTimezone('UTC')
            : CarbonImmutable::parse((string) $receipt->getAttribute('occurred_at'))->setTimezone('UTC');

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

    private function resolveSourceRecord(string $transitionId): ?Model
    {
        $map = [
            'user:' => User::class,
            'registration:' => Registration::class,
            'checkin:' => EventCheckin::class,
            'submission:' => EventSubmission::class,
            'bookmark:' => Bookmark::class,
            'response:' => Response::class,
            'follow:' => Follow::class,
            'saved_search:' => SavedSearch::class,
        ];

        foreach ($map as $prefix => $model) {
            if (! str_starts_with($transitionId, $prefix)) {
                continue;
            }

            $id = substr($transitionId, strlen($prefix));

            if ($id === '') {
                return null;
            }

            /** @var class-string<Model> $model */
            return $model::query()->whereKey($id)->first();
        }

        return null;
    }

    private function verificationFor(string $outcome, ?Model $source): RewardVerificationLevel
    {
        if ($outcome === 'event_checkin') {
            if ($source instanceof EventCheckin && $this->nullableString($source->getAttribute('verified_by_user_id')) !== null) {
                return RewardVerificationLevel::StaffVerified;
            }

            return RewardVerificationLevel::Unverified;
        }

        if ($outcome === 'event_registration') {
            if ($source instanceof Registration && in_array($source->statusValue(), ['confirmed', 'checked_in', 'completed'], true)) {
                return RewardVerificationLevel::Accepted;
            }

            return RewardVerificationLevel::Unverified;
        }

        if ($outcome === 'event_submission') {
            if ($source instanceof EventSubmission && $this->moderationValue($source) === 'approved') {
                return RewardVerificationLevel::ModeratedApproved;
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
    private function sourceInvalidates(string $outcome, ?Model $source): array
    {
        if (! $source instanceof Model) {
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

        if ($source instanceof Registration && in_array($source->statusValue(), ['cancelled', 'rejected', 'expired', 'refunded'], true)) {
            return ['cancelled' => true, 'invalid' => true];
        }

        if ($source instanceof EventSubmission && $this->moderationValue($source) === 'rejected') {
            return ['cancelled' => true, 'invalid' => true];
        }

        return ['cancelled' => false, 'invalid' => false];
    }

    private function actorRoleFor(string $outcome, ?Model $source): RewardBeneficiaryRole
    {
        if ($outcome === 'event_registration'
            && $source instanceof Registration
            && (int) $source->getAttribute('total_amount') > 0
            && (string) $source->getAttribute('payment_status') === 'paid'
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
        if ($affiliateId === '') {
            return null;
        }

        $claim = ShareGuestClaim::query()
            ->where('guest_affiliate_id', $affiliateId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if (! $claim instanceof ShareGuestClaim || ! $claim->isActive()) {
            return null;
        }

        return $this->nullableUuid($claim->getAttribute('claimant_user_id'));
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

    private function moderationValue(EventSubmission $source): string
    {
        $status = $source->getAttribute('status');

        if (is_string($status)) {
            return $status;
        }

        if (is_object($status) && method_exists($status, 'getValue')) {
            return (string) $status->getValue();
        }

        return (string) $status;
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
