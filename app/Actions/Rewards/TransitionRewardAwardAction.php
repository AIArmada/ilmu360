<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardBenefitCatalog;
use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Contracts\Rewards\RewardReservationResolver;
use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardMilestoneStatus;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAward;
use App\Models\RewardAwardEntry;
use App\Models\RewardCapCounter;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardMilestoneCycle;
use App\Models\RewardPayoutRequest;
use App\Models\RewardProgramVersion;
use App\Models\RewardSourceComponentClaim;
use App\Support\Rewards\NullRewardFinancialReadinessChecker;
use App\Support\Rewards\NullRewardSourceFactReader;
use App\Support\Rewards\RewardApprovalLocks;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardCapWindows;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardLedgerLockOrder;
use App\Support\Rewards\RewardPreviewContext;
use App\Support\Rewards\RewardPreviewEngine;
use App\Support\Rewards\RewardPreviewFixture;
use App\Support\Rewards\RewardSourceFact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff/system-driven award lifecycle transitions.
 *
 * Every transition appends an entry with actor/reason/evidence and stamps the
 * target's dedicated timestamp column once. Policy approval (held ->
 * approved) is recorded separately from actual component fulfillment:
 * recognition/points/badge fulfill through their dedicated actions in the
 * same transaction. Cash fulfillment stamps only when a CONFIRMED (paid)
 * payout request with posted backing exists — submitted-but-unpaid is
 * never presented as received. The payout pipeline (with provider calls
 * outside every transaction) runs before this transition, and no
 * monetary backing is ever fabricated here.
 * Benefit fulfillment delegates to the bound benefit catalog adapter and
 * refuses until one is bound.
 *
 * Guards: approval/fulfillment require a staff/admin user identity that is
 * not the recipient (caller own-approval is prohibited wherever identities
 * are supplied); rejection/reversal/correction additionally allow system
 * actors for job-driven corrections. Reversal of consumed point value opens
 * a dispute obligation instead of magically restoring value. Reversal with
 * active point reservations voids only host-attested unsubmitted holds and
 * blocks on any unknown reservation; the default null resolver attests
 * nothing, so unattested reversals refuse until the holds resolve through
 * ordinary release/capture.
 *
 * Qualifying authority: approval and fulfillment recheck current source
 * validity (unknown/cancelled/fraud-confirmed/corrected-withdrawn sources
 * refuse) through the reader boundary — the null reader truthfully refuses
 * every approval — plus the frozen source-qualification gates (outcomes,
 * categories, verification minimum, identity, attribution) evaluated by the
 * pure policy authority against the award's frozen rules at the original
 * decision time. Milestone aggregates recheck every frozen contributor's
 * current canonical evidence and cycle validity under lock; nothing is
 * skipped. Rejected awards carry no claim or cap consumption, so a
 * rejected-to-approved overturn must present explicit appeal override
 * evidence with targeted merit gates and acquire the same claim/cap
 * obligations as live evaluation; held/pending approvals verify the
 * obligations they already hold and never double-consume. Cash/benefit
 * approvals require financial readiness; unready candidates stay held and
 * are never falsely marked approved.
 */
final readonly class TransitionRewardAwardAction
{
    use AsAction;

    public function __construct(
        private ?RewardFinancialReadinessChecker $financialChecker = null,
        private ?RewardSourceFactReader $sourceReader = null,
        private ?RewardReservationResolver $reservationResolver = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function handle(
        RewardAward|string $award,
        RewardAwardStatus|string $to,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence = null,
        ?string $sourceOperationId = null,
        ?CarbonInterface $decidedAt = null,
        ?CarbonInterface $pointsExpiresAt = null,
    ): RewardAward {
        $toEnum = $to instanceof RewardAwardStatus ? $to : RewardAwardStatus::tryFrom($to);

        if (! $toEnum instanceof RewardAwardStatus) {
            throw new InvalidArgumentException('Unknown award status.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);
        RewardLedgerEntries::assertReason($reason);

        if ($sourceOperationId !== null && ($sourceOperationId === '' || mb_strlen($sourceOperationId) > 191)) {
            throw new InvalidArgumentException('Source operation id must be null or 1..191 characters.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $expiresAt = $pointsExpiresAt === null ? null : CarbonImmutable::parse($pointsExpiresAt)->setTimezone('UTC');
        $awardId = $award instanceof RewardAward ? (string) $award->getKey() : $award;
        $checker = $this->financialChecker ?? new NullRewardFinancialReadinessChecker;
        $reader = $this->sourceReader ?? new NullRewardSourceFactReader;

        try {
            return DB::transaction(fn (): RewardAward => $this->attempt(
                $awardId, $toEnum, $actorType, $actorId, $actorRole, $reason, $evidence, $sourceOperationId, $decided, $expiresAt, $checker, $reader,
            ));
        } catch (QueryException $exception) {
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            $current = RewardAward::query()->whereKey($awardId)->first();

            if ($current instanceof RewardAward && $current->status === $toEnum) {
                return $current;
            }

            throw new InvalidArgumentException('Concurrent transition conflict; reload the award and retry.', 0, $exception);
        }
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function attempt(
        string $awardId,
        RewardAwardStatus $toEnum,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        ?array $evidence,
        ?string $sourceOperationId,
        CarbonImmutable $decided,
        ?CarbonImmutable $expiresAt,
        RewardFinancialReadinessChecker $checker,
        RewardSourceFactReader $reader,
    ): RewardAward {
        $pre = RewardAward::query()->whereKey($awardId)->first();

        if (! $pre instanceof RewardAward) {
            throw new InvalidArgumentException('Unknown reward award.');
        }

        $isMilestone = self::isMilestoneAward($pre);
        $needsQualifyingGates = in_array($toEnum, [RewardAwardStatus::Approved, RewardAwardStatus::Fulfilled], true);

        if ($toEnum === RewardAwardStatus::Approved) {
            RewardApprovalLocks::lockCoordinatorChain($pre);
        }

        if ($isMilestone && $needsQualifyingGates) {
            $this->lockMilestoneCycle($pre);
        }

        $locked = RewardApprovalLocks::lockAward($awardId);

        if (! $locked instanceof RewardAward) {
            throw new InvalidArgumentException('Unknown reward award.');
        }

        if ($locked->status === $toEnum) {
            return $locked;
        }

        if ($sourceOperationId !== null) {
            $prior = RewardAwardEntry::query()
                ->where('award_id', $locked->getKey())
                ->where('source_operation_id', $sourceOperationId)
                ->first();

            if ($prior instanceof RewardAwardEntry) {
                if ((string) $prior->to_status !== $toEnum->value) {
                    throw new InvalidArgumentException('Source operation id is already bound to a different transition; supply a fresh id.');
                }

                return $locked;
            }
        }

        if (! $locked->status->canTransitionTo($toEnum)) {
            throw new InvalidArgumentException(
                "Invalid award transition [{$locked->status->value}] -> [{$toEnum->value}]."
            );
        }

        // Fulfilled cash/benefit value already moved: raw reversal would
        // silently skip payout recovery and grant-dispute settlement.
        // Only the fulfillment connector (which prepares recovery first
        // and says so in evidence) may reverse fulfilled money/benefits.
        if ($toEnum === RewardAwardStatus::Reversed
            && $locked->status === RewardAwardStatus::Fulfilled
            && $locked->component->requiresMoneyOrBenefitFulfillment()
            && ($evidence['reversal_prepared'] ?? null) !== true) {
            throw new InvalidArgumentException(
                'Fulfilled cash/benefit awards reverse only through the fulfillment connector; refusing blind reversal.'
            );
        }

        $this->assertRoleFor($locked, $toEnum, $actorType, $actorId, $actorRole);

        $entryEvidence = $evidence ?? [];
        $fromStatus = $locked->status;

        if ($locked->component->requiresMoneyOrBenefitFulfillment()) {
            $assessment = $toEnum === RewardAwardStatus::Fulfilled
                ? $checker->canFulfillFinancial($locked)
                : $checker->canApproveFinancial($locked);

            $entryEvidence['financial_readiness'] = [
                'ready' => $assessment->ready,
                'blockers' => $assessment->blockers,
                'notes' => $assessment->notes,
            ];

            if ($toEnum === RewardAwardStatus::Fulfilled && ! $assessment->ready) {
                throw new InvalidArgumentException(
                    'Refused: cash/benefit fulfillment requires completed financial readiness; the award stays approved. '
                    .'Missing: '.implode(', ', $assessment->blockers)
                );
            }

            if ($toEnum === RewardAwardStatus::Approved && ! $assessment->ready) {
                throw new InvalidArgumentException(
                    'Refused: cash/benefit approval requires funded financial readiness; the award stays a held candidate. '
                    .'Missing: '.implode(', ', $assessment->blockers)
                );
            }
        }

        $isOverturn = $fromStatus === RewardAwardStatus::Rejected && $toEnum === RewardAwardStatus::Approved;
        $meritOverride = [];

        if ($isOverturn) {
            $meritOverride = $this->assertOverturnAuthority($locked, $entryEvidence, $sourceOperationId);
            $this->obligateOverturnedCash($locked, $actorType, $actorId, $actorRole, $decided);
        } elseif (($entryEvidence['merit_override'] ?? null) !== null) {
            throw new InvalidArgumentException('Refused: merit overrides apply only to appeal overturns of rejected awards.');
        }

        if ($needsQualifyingGates) {
            $this->assertCurrentSourceValid($locked, $reader, $decided, $entryEvidence, $meritOverride, $isOverturn);
        }

        if ($isOverturn) {
            $this->acquireOverturnClaimAndCaps($locked, $decided);
        }

        if (! $isOverturn && $fromStatus !== RewardAwardStatus::Approved && $toEnum === RewardAwardStatus::Approved) {
            $this->verifyHeldObligations($locked, $decided);
        }

        if ($toEnum === RewardAwardStatus::Fulfilled) {
            $this->fulfillComponent($locked, $actorType, $actorId, $actorRole, $reason, $decided, $expiresAt);
        }

        if ($toEnum === RewardAwardStatus::Reversed && $locked->component === RewardComponent::Points) {
            $this->reverseConsumedPoints($locked, $actorType, $actorId, $actorRole, $reason, $decided);
        }

        if ($toEnum === RewardAwardStatus::Rejected) {
            $this->releaseRejectedCashObligation($locked, $actorType, $actorId, $actorRole, $decided);
        }

        $column = $toEnum->transitionColumn();

        if ($locked->getAttribute($column) !== null) {
            throw new InvalidArgumentException("Award [{$column}] is already stamped; transitions never rewrite history.");
        }

        $fromValue = $fromStatus->value;

        $locked->forceFill([
            'status' => $toEnum,
            $column => $decided,
        ])->save();

        RewardLedgerEntries::appendAwardEntry(
            $locked, $fromValue,
            $toEnum->value, $actorType, $actorId, $actorRole, $reason,
            $entryEvidence === [] ? null : $entryEvidence, $sourceOperationId, $decided,
        );

        // Gratitude follows committed changes in the same atomic unit:
        // approvals and fulfillments plan the recipient's delayed
        // digest; reversals cancel or recalculate pending material.
        // Planning is preference-gated — an opted-out or non-user
        // recipient simply yields no slot, never a failed transition.
        if ($toEnum === RewardAwardStatus::Approved || $toEnum === RewardAwardStatus::Fulfilled) {
            try {
                PlanRewardGratitudeDigestAction::run(
                    (string) $locked->recipient_type, (string) $locked->recipient_id,
                    ['awards'], $decided,
                );
            } catch (InvalidArgumentException) {
                // No slot for this recipient; the award stands.
            }
        } elseif ($toEnum === RewardAwardStatus::Reversed) {
            CancelRewardDigestAction::cancelPendingForRecipient(
                (string) $locked->recipient_type, (string) $locked->recipient_id,
                'award-reversed', $decided,
            );
        }

        return $locked->refresh();
    }

    private function lockMilestoneCycle(RewardAward $pre): void
    {
        $source = $pre->source_snapshot;
        $cycleId = is_array($source) ? ($source['cycle_id'] ?? null) : null;

        if (! is_string($cycleId) || $cycleId === '') {
            throw new InvalidArgumentException('Milestone award carries no frozen cycle; refusing an unqualified fulfillment.');
        }

        $cycle = RewardMilestoneCycle::query()->whereKey($cycleId)->lockForUpdate()->first();

        if (! $cycle instanceof RewardMilestoneCycle) {
            throw new InvalidArgumentException('Milestone cycle is missing; refusing an unqualified fulfillment.');
        }

        RewardMilestoneContribution::query()
            ->where('cycle_id', $cycle->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $entryEvidence
     * @param  list<string>  $meritOverride
     */
    private function assertCurrentSourceValid(
        RewardAward $award,
        RewardSourceFactReader $reader,
        CarbonImmutable $decided,
        array &$entryEvidence,
        array $meritOverride,
        bool $isOverturn,
    ): void {
        if (self::isMilestoneAward($award)) {
            $this->assertMilestoneContributorsValid($award, $reader, $decided, $entryEvidence);

            return;
        }

        $fact = $reader->read((string) $award->source_business_key);

        if (! $fact instanceof RewardSourceFact) {
            throw new InvalidArgumentException('Refused: unknown_source has no readable current fact; approval requires current source validity.');
        }

        $entryEvidence['source_recheck'] = [
            'fraud_status' => $fact->fraudStatus->value,
            'correction_status' => $fact->correctionStatus->value,
            'is_valid' => $fact->isValid,
            'verification' => $fact->verification->value,
            'checked_at' => $decided->toIso8601String(),
        ];

        if (! $fact->isValid) {
            throw new InvalidArgumentException('Refused: source_invalid at approval time; the award cannot advance.');
        }

        if ($fact->isBot) {
            throw new InvalidArgumentException('Refused: source_bot_excluded at approval time.');
        }

        if ($fact->isCancelled) {
            throw new InvalidArgumentException('Refused: source_cancelled at approval time.');
        }

        if ($fact->correctionStatus->value === 'withdrawn') {
            throw new InvalidArgumentException('Refused: source_withdrawn at approval time.');
        }

        if ($fact->fraudStatus->value === 'confirmed') {
            throw new InvalidArgumentException('Refused: fraud_confirmed at approval time.');
        }

        if ($fact->fraudStatus->value === 'suspect' && ! in_array('fraud_suspect_held', $meritOverride, true)) {
            throw new InvalidArgumentException('Refused: fraud_suspect_held at approval time; the award stays held.');
        }

        if ($fact->fraudStatus->value === 'suspect') {
            $entryEvidence['source_recheck']['fraud_suspect'] = 'cleared-by-merit-override';
        }

        if (! self::beneficiaryBinds($fact, $award->beneficiary_role, (string) $award->recipient_id)) {
            throw new InvalidArgumentException('Refused: beneficiary_mismatch at approval time.');
        }

        $this->assertFrozenSourceQualification($award, $fact, $decided, $entryEvidence, $meritOverride, $isOverturn);
    }

    /**
     * Milestone aggregate recheck: the frozen contributor set is re-read
     * against current canonical evidence while the cycle, contribution, and
     * award locks are held. A contributor that became cancelled, withdrawn,
     * fraud-confirmed, unverified, or otherwise unqualified after achievement
     * — but before the invalidation hook runs — refuses fulfillment; revoked
     * contributions are excluded, so above-target cycles fulfill once the
     * hook has processed the correction.
     *
     * @param  array<string, mixed>  $entryEvidence
     */
    private function assertMilestoneContributorsValid(
        RewardAward $award,
        RewardSourceFactReader $reader,
        CarbonImmutable $decided,
        array &$entryEvidence,
    ): void {
        $source = $award->source_snapshot;
        $cycleId = is_array($source) ? ($source['cycle_id'] ?? null) : null;

        if (! is_string($cycleId) || $cycleId === '') {
            throw new InvalidArgumentException('Refused: milestone award carries no frozen cycle; fulfillment requires qualified evidence.');
        }

        $cycle = RewardMilestoneCycle::query()->whereKey($cycleId)->lockForUpdate()->first();

        if (! $cycle instanceof RewardMilestoneCycle
            || (string) $cycle->award_id !== (string) $award->getKey()
        ) {
            throw new InvalidArgumentException('Refused: milestone cycle binding is incoherent; fulfillment requires an achieved cycle.');
        }

        if ($cycle->status !== RewardMilestoneStatus::Achieved || $cycle->invalidated_at !== null) {
            throw new InvalidArgumentException('Refused: milestone cycle is no longer achieved; fulfillment requires an achieved cycle.');
        }

        if ((int) $cycle->current_count < (int) $cycle->target_count) {
            throw new InvalidArgumentException('Refused: milestone cycle is below its target; fulfillment requires an achieved cycle.');
        }

        $contributions = RewardMilestoneContribution::query()
            ->where('cycle_id', $cycle->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(static fn (RewardMilestoneContribution $c): bool => $c->revoked_at === null)
            ->values();

        if ($contributions->count() < (int) $cycle->target_count) {
            throw new InvalidArgumentException('Refused: milestone cycle is below its target; fulfillment requires an achieved cycle.');
        }

        $version = $award->policy_version_id !== null
            ? RewardProgramVersion::query()->whereKey((string) $award->policy_version_id)->first()
            : null;

        if (! $version instanceof RewardProgramVersion) {
            throw new InvalidArgumentException('Refused: milestone award carries no frozen policy version; fulfillment requires qualified evidence.');
        }

        $eligibility = $version->rules['eligibility'] ?? null;
        $minimum = is_array($eligibility)
            ? RewardVerificationLevel::tryFrom((string) ($eligibility['min_verification'] ?? ''))
            : null;

        $checked = [];

        foreach ($contributions as $contribution) {
            $key = (string) $contribution->source_business_key;
            $fact = $reader->read($key);

            if (! $fact instanceof RewardSourceFact) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is unknown_source; the milestone cannot fulfill until its evidence is readable.");
            }

            if (! $fact->isValid) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is source_invalid; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->isBot) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is source_bot_excluded; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->isCancelled) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is source_cancelled; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->correctionStatus->value === 'withdrawn') {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is source_withdrawn; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->fraudStatus->value === 'confirmed') {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is fraud_confirmed; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->fraudStatus->value === 'suspect') {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is fraud_suspect_held; the milestone stays approved until the review clears.");
            }

            if (! $fact->isClaimedIdentity) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is guest_unclaimed; the milestone cannot fulfill until its correction is processed.");
            }

            if (! $fact->isAttributed) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is unattributed; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->actorId !== null && $fact->sharerId !== null && $fact->actorId === $fact->sharerId) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is self_credit_excluded; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->sharerId !== (string) $award->recipient_id && $fact->actorId !== (string) $award->recipient_id) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] no longer involves the recipient; the milestone cannot fulfill until its correction is processed.");
            }

            if ($fact->outcome->value !== (string) $contribution->outcome) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] outcome changed; the milestone cannot fulfill until its correction is processed.");
            }

            if ($minimum instanceof RewardVerificationLevel
                && ! $fact->verification->satisfiesFor($minimum, $fact->outcome)
            ) {
                throw new InvalidArgumentException("Refused: contributor [{$key}] is verification_insufficient under the frozen policy; the milestone cannot fulfill until its correction is processed.");
            }

            $checked[] = [
                'source' => $key,
                'verification' => $fact->verification->value,
                'fraud_status' => $fact->fraudStatus->value,
                'correction_status' => $fact->correctionStatus->value,
            ];
        }

        $entryEvidence['source_recheck'] = [
            'aggregate' => 'milestone',
            'cycle_id' => $cycleId,
            'target_count' => (int) $cycle->target_count,
            'contributor_count' => $contributions->count(),
            'contributors' => $checked,
            'checked_at' => $decided->toIso8601String(),
        ];
    }

    /**
     * Frozen source-qualification recheck for ordinary awards: the CURRENT
     * fact is evaluated by the existing pure policy authority against the
     * award's FROZEN rules (outcomes, categories, verification minimum,
     * first-only, identity, attribution) at the ORIGINAL decision time, so a
     * still-underverified or otherwise disqualified held source cannot
     * advance. Program/enrollment/acceptance values come from the frozen
     * evaluation-time snapshots — pause/leave never discard earned
     * obligations, and the award keeps its original recipient/consent/earning
     * terms. Policy Hold verdicts pass: staff review is exactly what resolves
     * a hold.
     *
     * @param  array<string, mixed>  $entryEvidence
     * @param  list<string>  $meritOverride
     */
    private function assertFrozenSourceQualification(
        RewardAward $award,
        RewardSourceFact $fact,
        CarbonImmutable $decided,
        array &$entryEvidence,
        array $meritOverride,
        bool $isOverturn,
    ): void {
        $policy = $award->policy_snapshot;

        if (! is_array($policy) || ! is_array($policy['eligibility'] ?? null)) {
            throw new InvalidArgumentException('Refused: frozen policy snapshot is missing mandatory qualification terms.');
        }

        $materialHash = $policy['material_terms_hash'] ?? null;

        if (! is_string($materialHash) || $materialHash === '') {
            throw new InvalidArgumentException('Refused: frozen policy snapshot is missing mandatory qualification terms.');
        }

        $decision = $this->originalDecisionTime($award, $decided);
        $consent = $award->consent_snapshot;

        if (is_array($consent) && is_string($consent['acceptance_id'] ?? null) && $consent['acceptance_id'] !== '') {
            $acceptanceId = $consent['acceptance_id'];
            $acceptanceHash = $consent['material_terms_hash'] ?? null;
            $acceptedAt = $consent['accepted_at'] ?? null;

            if (! is_string($acceptanceHash) || ! is_string($acceptedAt)) {
                throw new InvalidArgumentException('Refused: frozen consent snapshot is incoherent.');
            }

            $enrollmentId = $award->enrollment_id !== null ? (string) $award->enrollment_id : null;

            if ($enrollmentId === null) {
                throw new InvalidArgumentException('Refused: frozen consent snapshot is incoherent.');
            }

            $joinedAt = $this->coveringJoinedAt($enrollmentId, $decision);

            if ($joinedAt === null) {
                throw new InvalidArgumentException('Refused: frozen enrollment coverage is incoherent; the award cannot advance.');
            }

            $acceptance = [
                'id' => $acceptanceId,
                'hash' => $acceptanceHash,
                'accepted_at' => CarbonImmutable::parse($acceptedAt)->setTimezone('UTC'),
                'joined_at' => $joinedAt,
                'via_override' => false,
            ];
        } else {
            if (! $isOverturn || ! in_array('enrollment_prerequisites', $meritOverride, true)) {
                throw new InvalidArgumentException('Refused: unresolved consent cannot advance without an explicit enrollment-prerequisites merit override.');
            }

            $acceptance = $this->resolveOverturnMembership($award, $decision);

            if ($acceptance === null) {
                throw new InvalidArgumentException('Refused: overturn requires an enrolled member with covering terms acceptance at the earning decision.');
            }
        }

        $components = [];

        foreach ((is_array($policy['components'] ?? null) ? $policy['components'] : []) as $entry) {
            if (is_array($entry) && is_string($entry['type'] ?? null)) {
                $components[] = $entry['type'];
            }
        }

        $stacking = is_array($policy['stacking'] ?? null) ? $policy['stacking'] : [];

        $context = new RewardPreviewContext(
            programFamilyKey: $award->family_key,
            programStatus: RewardProgramStatus::Active,
            versionFound: true,
            versionId: $award->policy_version_id !== null ? (string) $award->policy_version_id : null,
            versionNumber: isset($policy['policy_version']) && is_int($policy['policy_version']) ? $policy['policy_version'] : null,
            versionRules: [
                'eligibility' => $policy['eligibility'],
                'windows' => is_array($policy['windows'] ?? null) ? $policy['windows'] : [],
                'holds' => is_array($policy['holds'] ?? null) ? $policy['holds'] : [],
            ],
            versionComponents: $components,
            versionMaterialHash: $materialHash,
            versionEffectiveFrom: isset($policy['effective_from']) && is_string($policy['effective_from'])
                ? CarbonImmutable::parse($policy['effective_from'])->setTimezone('UTC')
                : null,
            compatibleWith: array_values(array_filter(
                is_array($stacking['compatible_with'] ?? null) ? $stacking['compatible_with'] : [],
                is_string(...)
            )),
            enrollmentExists: true,
            enrollmentActiveAtDecision: true,
            acceptanceId: $acceptance['id'],
            acceptanceMaterialHash: $acceptance['hash'],
            acceptanceAcceptedAt: $acceptance['accepted_at'],
            hasPriorAcceptance: true,
            relevantPeriodJoinedAt: $acceptance['joined_at'],
            fixture: new RewardPreviewFixture(
                sourceBusinessKey: $fact->sourceBusinessKey,
                outcome: $fact->outcome,
                category: $fact->category,
                occurredAt: $fact->occurredAt,
                verification: $fact->verification,
                isFirstTime: $fact->isFirstTime,
                actorId: $fact->actorId,
                sharerId: $fact->sharerId,
                isClaimedIdentity: $fact->isClaimedIdentity,
                isAttributed: $fact->isAttributed,
                isAmbiguousDevice: $fact->isAmbiguousDevice,
                claimedFamilyKeys: [],
                isBot: $fact->isBot,
                isCancelled: $fact->isCancelled,
                isValidSource: $fact->isValid,
            ),
            requestedComponent: $award->component,
            decisionTime: $decision,
        );

        $verdict = (new RewardPreviewEngine)->evaluate($context);
        $reasons = array_map(static fn (RewardPreviewReason $reason): string => $reason->value, $verdict->reasons);

        if ($verdict->decision === RewardPreviewDecision::Ineligible) {
            $primary = self::primaryEngineReason($verdict->reasons);

            throw new InvalidArgumentException("Refused: {$primary} at approval time; the award cannot advance under its frozen policy.");
        }

        $entryEvidence['frozen_qualification'] = [
            'verdict' => $verdict->decision === RewardPreviewDecision::Hold ? 'hold-resolved-by-review' : 'eligible',
            'reasons' => $reasons,
            'membership_via_override' => $acceptance['via_override'],
            'checked_at' => $decided->toIso8601String(),
        ];
    }

    /**
     * @param  list<RewardPreviewReason>  $reasons
     */
    private static function primaryEngineReason(array $reasons): string
    {
        foreach ($reasons as $reason) {
            if ($reason !== RewardPreviewReason::Ok && $reason !== RewardPreviewReason::LiveChecksDeferred) {
                return $reason->value;
            }
        }

        return RewardPreviewReason::Ok->value;
    }

    private function originalDecisionTime(RewardAward $award, CarbonImmutable $decided): CarbonImmutable
    {
        foreach (['held_at', 'approved_at', 'rejected_at'] as $column) {
            $value = $award->getAttribute($column);

            if ($value !== null) {
                return CarbonImmutable::parse($value)->setTimezone('UTC');
            }
        }

        return $decided;
    }

    private function coveringJoinedAt(string $enrollmentId, CarbonImmutable $decision): ?CarbonImmutable
    {
        $period = RewardEnrollmentPeriod::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('joined_at', '<=', $decision)
            ->where(function ($query) use ($decision): void {
                $query->whereNull('left_at')->orWhere('left_at', '>', $decision);
            })
            ->orderByDesc('joined_at')
            ->first();

        if (! $period instanceof RewardEnrollmentPeriod) {
            return null;
        }

        return CarbonImmutable::parse($period->joined_at)->setTimezone('UTC');
    }

    /**
     * Overturn membership for rejected awards with unresolved frozen consent:
     * real enrollment history covering the earning decision plus a
     * matching-hash acceptance — never synthesized values. The caller must
     * already hold the explicit enrollment-prerequisites merit override.
     *
     * @return array{id: string, hash: string, accepted_at: CarbonImmutable, joined_at: ?CarbonImmutable, via_override: true}|null
     */
    private function resolveOverturnMembership(RewardAward $award, CarbonImmutable $decision): ?array
    {
        if ($award->program_id === null) {
            return null;
        }

        $policy = $award->policy_snapshot;
        $materialHash = is_array($policy) ? ($policy['material_terms_hash'] ?? null) : null;

        if (! is_string($materialHash) || $materialHash === '') {
            return null;
        }

        $enrollment = RewardEnrollment::query()
            ->where('program_id', (string) $award->program_id)
            ->where('member_type', (string) $award->recipient_type)
            ->where('member_id', (string) $award->recipient_id)
            ->orderByDesc('enrolled_at')
            ->first();

        if (! $enrollment instanceof RewardEnrollment) {
            return null;
        }

        $joinedAt = $this->coveringJoinedAt((string) $enrollment->getKey(), $decision);

        if ($joinedAt === null) {
            return null;
        }

        $acceptance = RewardEnrollmentAcceptance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('accepted_at', '<=', $decision)
            ->where('material_terms_hash', $materialHash)
            ->orderByDesc('accepted_at')
            ->orderByDesc('seq')
            ->first();

        if (! $acceptance instanceof RewardEnrollmentAcceptance) {
            return null;
        }

        return [
            'id' => (string) $acceptance->getKey(),
            'hash' => (string) $acceptance->material_terms_hash,
            'accepted_at' => CarbonImmutable::parse($acceptance->accepted_at)->setTimezone('UTC'),
            'joined_at' => $joinedAt,
            'via_override' => true,
        ];
    }

    /**
     * Held/pending approvals confirm the obligations evaluation already
     * recorded — the family's source claim plus every frozen cap counter —
     * without consuming anything twice. Claims and counters are never
     * deleted, so absence means incoherence, never a retry.
     */
    private function verifyHeldObligations(RewardAward $award, CarbonImmutable $decided): void
    {
        $claim = RewardSourceComponentClaim::query()
            ->where('source_business_key', (string) $award->source_business_key)
            ->where('component', $award->component->value)
            ->where('beneficiary_role', $award->beneficiary_role->value)
            ->where('family_key', $award->family_key)
            ->first();

        if (! $claim instanceof RewardSourceComponentClaim) {
            throw new InvalidArgumentException('Refused: held award carries no source claim; the approval is incoherent.');
        }

        if ($award->policy_version_id === null) {
            throw new InvalidArgumentException('Refused: held award carries no frozen policy version; the approval is incoherent.');
        }

        $version = RewardProgramVersion::query()->whereKey((string) $award->policy_version_id)->first();

        if (! $version instanceof RewardProgramVersion) {
            throw new InvalidArgumentException('Refused: held award carries no frozen policy version; the approval is incoherent.');
        }

        $caps = $version->rules['caps'] ?? null;

        if (! is_array($caps) || $caps === []) {
            return;
        }

        $decision = $this->originalDecisionTime($award, $decided);
        $recipientKey = RewardCapWindows::recipientKey((string) $award->recipient_type, (string) $award->recipient_id);
        $targets = [];

        foreach ($caps as $cap) {
            if (! is_array($cap) || ! is_string($cap['scope'] ?? null) || ! is_int($cap['limit'] ?? null)) {
                continue;
            }

            $keys = RewardCapWindows::keysFor(
                $cap['scope'], $award->family_key, (string) $award->program_id,
                $recipientKey, (string) $award->source_business_key, $award->component->value, $decision,
            );

            $targets[$keys['cap_key'].'|'.$keys['window_key']] = [
                'scope' => $cap['scope'],
                'limit' => $cap['limit'],
                'cap_key' => $keys['cap_key'],
                'window_key' => $keys['window_key'],
            ];
        }

        foreach (RewardLedgerLockOrder::sorted(array_keys($targets)) as $ordered) {
            $target = $targets[$ordered];

            $counter = RewardCapCounter::query()
                ->where('cap_key', $target['cap_key'])
                ->where('window_key', $target['window_key'])
                ->lockForUpdate()
                ->first();

            if (! $counter instanceof RewardCapCounter) {
                throw new InvalidArgumentException('Refused: held award carries no cap obligation for scope ['.$target['scope'].']; the approval is incoherent.');
            }

            if ($counter->limit_value !== $target['limit']) {
                throw new InvalidArgumentException("Cap [{$target['scope']}] limit changed under a frozen window; refusing an incoherent approval.");
            }
        }
    }

    private static function beneficiaryBinds(RewardSourceFact $fact, RewardBeneficiaryRole $role, string $recipientId): bool
    {
        return match ($role) {
            RewardBeneficiaryRole::Recipient, RewardBeneficiaryRole::Sharer => $fact->sharerId !== null && $fact->sharerId === $recipientId,
            RewardBeneficiaryRole::Participant, RewardBeneficiaryRole::Purchaser => $fact->actorId !== null && $fact->actorId === $recipientId,
            RewardBeneficiaryRole::Staff => $fact->actorRole === RewardBeneficiaryRole::Staff && $fact->actorId !== null && $fact->actorId === $recipientId,
        };
    }

    /**
     * Rejection retires the held cash obligation: the award is no longer
     * a debt, so its reserve returns under the same authority (same key
     * the request path retires), and idempotent replay makes a second
     * release — or a release after a payout request already retired it —
     * a silent no-op instead of a double credit.
     */
    private function releaseRejectedCashObligation(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): void {
        if ($award->component !== RewardComponent::Cash) {
            return;
        }

        $epoch = $award->obligation_epoch;
        $minor = $award->obligated_minor;

        if (! is_int($epoch) || ! is_int($minor) || $minor <= 0) {
            return;
        }

        $amount = $award->amount_snapshot ?? [];
        $currency = (string) ($amount['currency'] ?? '');

        if ($currency === '') {
            return;
        }

        $currency = RewardBudgetLedger::normalizeCurrency($currency);
        $programId = (string) $award->program_id;

        RewardBudgetLedger::lockFunding($programId, $currency);

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Release,
            RewardBudgetLedger::releaseObligateKey((string) $award->getKey(), $epoch),
            $programId, $currency, $minor, (string) $award->getKey(),
            $actorType, $actorId, $actorRole, $decided,
        );
    }

    /**
     * A rejected-to-approved overturn resurrects the debt under a fresh
     * obligation epoch: the rejection retired epoch N, so the revived
     * award reserves under epoch N+1 and can never replay under the
     * retired key. Runs after authority checks and before the status
     * stamp (same transaction), so a refused overturn leaves no debt.
     */
    private function obligateOverturnedCash(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        CarbonImmutable $decided,
    ): void {
        if ($award->component !== RewardComponent::Cash) {
            return;
        }

        $amount = $award->amount_snapshot ?? [];
        $minor = $amount['minor_units'] ?? null;
        $currency = (string) ($amount['currency'] ?? '');

        if (! is_int($minor) || $minor <= 0 || $currency === '') {
            throw new InvalidArgumentException('Cash awards must freeze a positive minor-unit amount and currency.');
        }

        $currency = RewardBudgetLedger::normalizeCurrency($currency);
        $programId = (string) $award->program_id;
        $epoch = (is_int($award->obligation_epoch) ? $award->obligation_epoch : 0) + 1;

        RewardBudgetLedger::lockFunding($programId, $currency);

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Reserve,
            RewardBudgetLedger::obligateKey((string) $award->getKey(), $epoch),
            $programId, $currency, $minor, (string) $award->getKey(),
            $actorType, $actorId, $actorRole, $decided,
        );

        $award->forceFill(['obligation_epoch' => $epoch, 'obligated_minor' => $minor])->save();
    }

    public static function isMilestoneAward(RewardAward $award): bool
    {
        if (str_starts_with((string) $award->source_business_key, 'milestone:')) {
            return true;
        }

        $policy = $award->policy_snapshot;

        return is_array($policy) && array_key_exists('milestone_key', $policy);
    }

    /**
     * Targeted merit-override gates an appeal review may explicitly clear.
     * Fraud suspicion is reviewable evidence; enrollment prerequisites cover
     * rejected awards that froze no consent because evaluation rejected them
     * before the membership checks. Every other gate — validity, bot,
     * cancellation, withdrawal, confirmed fraud, verification, outcome,
     * beneficiary, caps, claims, finance — is never overridable.
     *
     * @var list<string>
     */
    public const array MERIT_OVERRIDE_GATES = ['fraud_suspect_held', 'enrollment_prerequisites'];

    /**
     * @param  array<string, mixed>  $entryEvidence
     * @return list<string>
     */
    private function assertOverturnAuthority(RewardAward $award, array $entryEvidence, ?string $sourceOperationId): array
    {
        $appealId = $entryEvidence['appeal_id'] ?? null;
        $authority = $entryEvidence['override_authority'] ?? null;

        if (! is_string($appealId) || $appealId === ''
            || $authority !== 'appeal-merit-override'
            || $sourceOperationId === null
            || ! str_starts_with($sourceOperationId, 'appeal-overturn:')
        ) {
            throw new InvalidArgumentException('Refused: rejected-to-approved overturns require explicit appeal-merit-override evidence and an appeal-overturn operation id.');
        }

        $override = $entryEvidence['merit_override'] ?? [];

        if (! is_array($override) || ! array_is_list($override)) {
            throw new InvalidArgumentException('Refused: merit overrides must be an explicit list of gate names.');
        }

        foreach ($override as $gate) {
            if (! is_string($gate) || ! in_array($gate, self::MERIT_OVERRIDE_GATES, true)) {
                throw new InvalidArgumentException('Refused: unknown merit-override gate ['.(is_string($gate) ? $gate : gettype($gate)).']; overrides are explicit and targeted.');
            }
        }

        return array_values(array_unique($override));
    }

    private function acquireOverturnClaimAndCaps(RewardAward $award, CarbonImmutable $decided): void
    {
        $version = $award->policy_version_id !== null
            ? RewardProgramVersion::query()->whereKey((string) $award->policy_version_id)->first()
            : null;

        if (! $version instanceof RewardProgramVersion) {
            throw new InvalidArgumentException('Refused: overturn requires the frozen policy version; milestone aggregates overturn through contribution correction.');
        }

        $compatible = array_values(array_filter($version->compatible_with, is_string(...)));

        $claims = RewardSourceComponentClaim::query()
            ->where('source_business_key', (string) $award->source_business_key)
            ->where('component', $award->component->value)
            ->where('beneficiary_role', $award->beneficiary_role->value)
            ->orderBy('family_key')
            ->lockForUpdate()
            ->get();

        foreach ($claims as $claim) {
            if ($claim->family_key === $award->family_key) {
                throw new InvalidArgumentException('Refused: stacking_conflict — this family already holds the source claim.');
            }

            if (! in_array($claim->family_key, $compatible, true)
                || ! in_array($award->family_key, $claim->compatible_snapshot, true)
            ) {
                throw new InvalidArgumentException('Refused: stacking_conflict — an incompatible family already holds the source claim.');
            }
        }

        $rules = $version->rules;
        $caps = is_array($rules['caps'] ?? null) ? $rules['caps'] : [];
        $recipientKey = RewardCapWindows::recipientKey((string) $award->recipient_type, (string) $award->recipient_id);
        $targets = [];

        foreach ($caps as $cap) {
            if (! is_array($cap) || ! is_string($cap['scope'] ?? null) || ! is_int($cap['limit'] ?? null)) {
                continue;
            }

            $keys = RewardCapWindows::keysFor(
                $cap['scope'], $award->family_key, (string) $award->program_id,
                $recipientKey, (string) $award->source_business_key, $award->component->value, $decided,
            );

            $targets[$keys['cap_key'].'|'.$keys['window_key']] = [
                'scope' => $cap['scope'],
                'limit' => $cap['limit'],
                'cap_key' => $keys['cap_key'],
                'window_key' => $keys['window_key'],
            ];
        }

        foreach (RewardLedgerLockOrder::sorted(array_keys($targets)) as $ordered) {
            $target = $targets[$ordered];

            $counter = RewardCapCounter::query()
                ->where('cap_key', $target['cap_key'])
                ->where('window_key', $target['window_key'])
                ->lockForUpdate()
                ->first();

            if (! $counter instanceof RewardCapCounter) {
                $counter = RewardCapCounter::query()->create([
                    'cap_key' => $target['cap_key'],
                    'window_key' => $target['window_key'],
                    'family_key' => $target['scope'] === 'family_source_component' ? '*' : $award->family_key,
                    'scope' => $target['scope'],
                    'limit_value' => $target['limit'],
                    'consumed' => 0,
                ]);
            }

            if ($counter->limit_value !== $target['limit']) {
                throw new InvalidArgumentException("Cap [{$target['scope']}] limit changed under a frozen window; refusing to overspend.");
            }

            if ($counter->consumed >= $counter->limit_value) {
                throw new InvalidArgumentException('Refused: cap_exceeded for scope ['.$target['scope'].']; overturn cannot bypass quotas.');
            }

            $counter->forceFill(['consumed' => $counter->consumed + 1])->save();
        }

        RewardSourceComponentClaim::query()->create([
            'source_business_key' => (string) $award->source_business_key,
            'component' => $award->component,
            'beneficiary_role' => $award->beneficiary_role,
            'family_key' => $award->family_key,
            'program_id' => $award->program_id,
            'award_id' => $award->getKey(),
            'compatible_snapshot' => $compatible,
            'claimed_at' => $decided,
        ]);
    }

    private function assertRoleFor(
        RewardAward $award,
        RewardAwardStatus $to,
        string $actorType,
        ?string $actorId,
        string $actorRole,
    ): void {
        $staffDecision = in_array($to, [
            RewardAwardStatus::Approved,
            RewardAwardStatus::Fulfilled,
        ], true);

        if ($staffDecision && ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true))) {
            throw new InvalidArgumentException('Approval and fulfillment require a staff/admin user identity.');
        }

        if (! $staffDecision && ! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Award transitions require a staff/admin/system identity.');
        }

        if ($actorType === 'user'
            && $actorId !== null
            && $actorId === (string) $award->recipient_id
            && in_array($to, [RewardAwardStatus::Approved, RewardAwardStatus::Fulfilled], true)
        ) {
            throw new InvalidArgumentException('Caller own-approval is prohibited: the approver must not be the recipient.');
        }
    }

    private function fulfillComponent(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        CarbonImmutable $decided,
        ?CarbonImmutable $pointsExpiresAt,
    ): void {
        $amount = $award->amount_snapshot;

        if ($award->component === RewardComponent::Points) {
            $points = $amount['points'] ?? null;
            $unit = $amount['unit'] ?? null;

            if (! is_int($points) || ! is_string($unit)) {
                throw new InvalidArgumentException('Points award amount snapshot is missing points/unit.');
            }

            $frozenExpiry = self::frozenPointsExpiry($amount, $decided);

            if ($pointsExpiresAt !== null) {
                $caller = $pointsExpiresAt->setTimezone('UTC')->toIso8601String();
                $frozen = $frozenExpiry?->setTimezone('UTC')->toIso8601String();

                if ($caller !== $frozen) {
                    throw new InvalidArgumentException('Points expiry derives from frozen published terms; caller expiry must match or be omitted.');
                }
            }

            CreditRewardPointsAction::run(
                (string) $award->recipient_type,
                (string) $award->recipient_id,
                $award->family_key,
                $unit,
                $points,
                $decided,
                $frozenExpiry,
                (string) $award->getKey(),
                $award->program_id !== null ? (string) $award->program_id : null,
                null,
                $actorType,
                $actorId,
                'award-fulfillment:'.$reason,
                $decided,
            );
        }

        if ($award->component === RewardComponent::Cash) {
            $this->fulfillCash($award, $decided);
        }

        if ($award->component === RewardComponent::Benefit) {
            $this->fulfillBenefit($award, $decided);
        }

        if ($award->component === RewardComponent::Badge) {
            $badgeKey = $amount['badge_key'] ?? null;

            if (! is_string($badgeKey)) {
                throw new InvalidArgumentException('Badge award amount snapshot is missing badge_key.');
            }

            GrantRewardBadgeAction::run(
                (string) $award->recipient_type,
                (string) $award->recipient_id,
                $award->family_key,
                $badgeKey,
                $award->program_id !== null ? (string) $award->program_id : null,
                (string) $award->getKey(),
                $actorType,
                $actorId,
                $actorRole,
                $reason,
                null,
                $decided,
            );
        }
    }

    /**
     * Cash fulfillment is a guarded recording step: the payout pipeline
     * (request → independent approve → submit through the real rail) runs
     * BEFORE this transition, with provider calls outside every
     * transaction. Fulfillment only stamps when the durable request is
     * submitted (manual bank awaits independent confirmation against the
     * frozen snapshot) or confirmed. Unknown/failed requests refuse; the
     * award stays approved and the operator reconciles or requeues.
     */
    private function fulfillCash(RewardAward $award, CarbonImmutable $decided): void
    {
        $request = RewardPayoutRequest::query()
            ->where('award_id', $award->getKey())
            ->lockForUpdate()
            ->first();

        if (! $request instanceof RewardPayoutRequest) {
            throw new InvalidArgumentException('Refused: cash fulfillment requires a confirmed payout request.');
        }

        if ($request->status !== RewardPayoutRequestStatus::Confirmed) {
            throw new InvalidArgumentException(
                'Refused: cash fulfillment requires a confirmed (paid) payout; current state is ['
                .$request->status->value.']. Confirm or reconcile the payout first.'
            );
        }

        if ($request->backing_conversion_id === null || $request->affiliate_payout_id === null) {
            throw new InvalidArgumentException('Refused: cash fulfillment requires posted backing and a payout operation.');
        }
    }

    /**
     * Benefit fulfillment delegates to the bound benefit catalog adapter
     * (P6 scope). Until an adapter is bound, benefit fulfillment refuses
     * with explicit blockers — the award stays approved.
     */
    private function fulfillBenefit(RewardAward $award, CarbonImmutable $decided): void
    {
        if (! interface_exists(RewardBenefitCatalog::class) || ! app()->bound(RewardBenefitCatalog::class)) {
            throw new InvalidArgumentException(
                'Refused: benefit fulfillment requires the benefit catalog adapter; the award stays approved. '
                .'Missing: benefit-fulfillment-adapter-missing'
            );
        }

        app(RewardBenefitCatalog::class)->fulfill($award, $decided);
    }

    private function reverseConsumedPoints(
        RewardAward $award,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        CarbonImmutable $decided,
    ): void {
        (new ClawBackAwardPointsAction($this->reservationResolver))->handle(
            (string) $award->getKey(),
            $actorType,
            $actorId,
            $actorRole,
            $reason,
            $decided,
        );
    }

    /**
     * @param  array<string, mixed>  $amount
     */
    public static function frozenPointsExpiry(array $amount, CarbonImmutable $decided): ?CarbonImmutable
    {
        $absolute = $amount['expires_at'] ?? null;

        if (is_string($absolute) && $absolute !== '') {
            $expiry = CarbonImmutable::parse($absolute)->setTimezone('UTC');

            if (! $expiry->greaterThan($decided)) {
                throw new InvalidArgumentException('Frozen points expiry must be after fulfillment.');
            }

            return $expiry;
        }

        $days = $amount['expires_in_days'] ?? null;

        if (is_int($days)) {
            if ($days < 1 || $days > 3650) {
                throw new InvalidArgumentException('Frozen points expires_in_days must be 1..3650.');
            }

            return $decided->addDays($days);
        }

        return null;
    }
}
