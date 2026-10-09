<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardPreviewEvaluator;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardVerificationLevel;

/**
 * Pure deterministic qualification preview.
 *
 * Clock/version/applicability plan:
 * - Decision time (first legitimate entitlement decision) selects the
 *   applicable policy version: the latest published version with
 *   effective_from <= decision. Occurrence never selects the policy.
 * - Occurrence (evidence) time determines factual evidence, attribution, and
 *   lookback eligibility. Evidence after the decision never qualifies.
 * - A permitted pre-claim source (occurred before the relevant active period
 *   join or before the applicable version's effective_from) qualifies only
 *   inside the version's published preclaim_lookback_days AND only when the
 *   source was already attributed — a claim never converts unattributed
 *   evidence into attributed credit, and there is no bulk historical replay.
 * - Material terms changes block new qualification until a matching-hash
 *   reacceptance; frozen acceptance references on accrued obligations are
 *   untouched (prospective). Advance consent for a future version never
 *   invalidates still-valid current consent.
 * - Product invariants are unconditional: no reward for unattributed, self,
 *   bot, invalid, or cancelled evidence, or for unclaimed guests. Validated
 *   rules always carry these protections as true; the engine enforces them
 *   even if a stored snapshot somehow omits them.
 *
 * No database, clock, or request access. Gates run in fixed order; identical
 * inputs always yield identical outputs.
 */
final class RewardPreviewEngine implements RewardPreviewEvaluator
{
    public function evaluate(RewardPreviewContext $context): RewardPreviewResult
    {
        $fixture = $context->fixture;
        $rules = $context->versionRules;

        /** @var list<RewardPreviewReason> $reasons */
        $reasons = [];
        /** @var list<string> $explanations */
        $explanations = [];

        $add = function (RewardPreviewReason $reason, string $explanation) use (&$reasons, &$explanations): void {
            $reasons[] = $reason;
            $explanations[] = $explanation;
        };

        if ($context->programStatus !== RewardProgramStatus::Active) {
            $add(RewardPreviewReason::ProgramNotActive, 'Program status ['.$context->programStatus->value.'] is not active.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $context->versionFound || $context->versionId === null) {
            $add(RewardPreviewReason::VersionMissing, 'No published version covers the decision time; no late attribution outside the effective timeline.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! in_array($context->requestedComponent->value, $context->versionComponents, true)) {
            $add(RewardPreviewReason::ComponentNotEnabled, 'Component ['.$context->requestedComponent->value.'] is not enabled by the applicable version.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        $eligibility = is_array($rules['eligibility'] ?? null) ? $rules['eligibility'] : [];
        $outcomes = is_array($eligibility['outcomes'] ?? null) ? $eligibility['outcomes'] : [];

        if (! in_array($fixture->outcome->value, $outcomes, true)) {
            $add(RewardPreviewReason::OutcomeNotAllowlisted, 'Outcome ['.$fixture->outcome->value.'] is not allowlisted by the applicable version.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        $categories = is_array($eligibility['categories'] ?? null) ? $eligibility['categories'] : [];

        if ($categories !== [] && ($fixture->category === null || ! in_array($fixture->category, $categories, true))) {
            $add(RewardPreviewReason::CategoryNotAllowlisted, 'Category ['.($fixture->category ?? 'none').'] is not allowlisted by the applicable version.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $fixture->isValidSource) {
            $add(RewardPreviewReason::SourceInvalid, 'Fixture source is marked invalid; invalid evidence never qualifies.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($fixture->isBot) {
            $add(RewardPreviewReason::SourceBotExcluded, 'Fixture source is bot activity; bot evidence never qualifies.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($fixture->isCancelled) {
            $add(RewardPreviewReason::SourceCancelled, 'Fixture source is cancelled; cancelled evidence never qualifies.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        $minimum = RewardVerificationLevel::tryFrom((string) ($eligibility['min_verification'] ?? '')) ?? RewardVerificationLevel::ModeratedApproved;

        if (! $fixture->verification->satisfiesFor($minimum, $fixture->outcome)) {
            $add(RewardPreviewReason::VerificationInsufficient, 'Verification ['.$fixture->verification->value.'] does not satisfy ['.$minimum->value.'] for outcome ['.$fixture->outcome->value.']; accepted registration is never verified attendance and moderation only vouches for submissions.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (($eligibility['first_only'] ?? false) === true && ! $fixture->isFirstTime) {
            $add(RewardPreviewReason::RecurringExcluded, 'Recurring evidence is excluded; this version rewards first-time outcomes only.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($fixture->actorId !== null
            && $fixture->sharerId !== null
            && $fixture->actorId === $fixture->sharerId
        ) {
            $add(RewardPreviewReason::SelfCreditExcluded, 'Self credit is excluded: the actor is the sharer.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $fixture->isClaimedIdentity) {
            $add(RewardPreviewReason::GuestUnclaimed, 'No component is issued to an unclaimed guest identity.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $fixture->isAttributed) {
            $add(RewardPreviewReason::LateAttributionRefused, 'Unattributed evidence cannot earn; a claim never converts it into attributed credit.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $context->enrollmentExists) {
            $add(RewardPreviewReason::EnrollmentMissing, 'Enrollment is required and no enrollment exists.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if (! $context->enrollmentActiveAtDecision) {
            $add(RewardPreviewReason::EnrollmentLeft, 'Enrollment is not active at the decision time; leaving stops new qualification (accrued obligations keep frozen terms).');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($context->acceptanceId === null || $context->acceptanceAcceptedAt === null) {
            if ($context->hasPriorAcceptance) {
                $add(RewardPreviewReason::AcceptanceStaleMaterialChange, 'Material terms changed; reacceptance is required before new qualification (prospective only).');
            } else {
                $add(RewardPreviewReason::AcceptanceMissing, 'No terms acceptance covers the decision time.');
            }

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($context->acceptanceAcceptedAt->greaterThan($context->decisionTime)) {
            $add(RewardPreviewReason::AcceptedAfterDecision, 'Acceptance after the decision time cannot authorize earlier evidence.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($context->acceptanceMaterialHash === null || $context->acceptanceMaterialHash !== $context->versionMaterialHash) {
            $add(RewardPreviewReason::AcceptanceStaleMaterialChange, 'Material terms changed; reacceptance is required before new qualification (prospective only).');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        if ($fixture->occurredAt->greaterThan($context->decisionTime)) {
            $add(RewardPreviewReason::EvidenceAfterDecision, 'Evidence occurring after the decision time cannot qualify.');

            return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
        }

        $beforePeriod = $context->relevantPeriodJoinedAt !== null
            && $fixture->occurredAt->lessThan($context->relevantPeriodJoinedAt);
        $beforeEffective = $context->versionEffectiveFrom !== null
            && $fixture->occurredAt->lessThan($context->versionEffectiveFrom);

        if ($beforePeriod || $beforeEffective) {
            $windows = is_array($rules['windows'] ?? null) ? $rules['windows'] : [];
            $lookback = $windows['preclaim_lookback_days'] ?? null;

            if (! is_int($lookback)) {
                $add(RewardPreviewReason::OutsidePreclaimLookback, 'No published preclaim lookback permits pre-enrollment or pre-effective evidence.');

                return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
            }

            $earliest = $context->decisionTime->subDays($lookback);

            if ($fixture->occurredAt->lessThan($earliest)) {
                $add(RewardPreviewReason::OutsidePreclaimLookback, 'Pre-enrollment or pre-effective evidence falls outside the published '.$lookback.'-day preclaim lookback; no historical replay.');

                return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
            }
        }

        foreach ($fixture->claimedFamilyKeys as $claimedFamily) {
            if (! in_array($claimedFamily, $context->compatibleWith, true)) {
                $add(RewardPreviewReason::StackingConflict, 'Source is already claimed by family ['.$claimedFamily.'] outside the frozen compatible list; default is one family per source/component.');

                return $this->result(RewardPreviewDecision::Ineligible, $context, $reasons, $explanations);
            }
        }

        $holds = is_array($rules['holds'] ?? null) ? $rules['holds'] : [];
        $windows = is_array($rules['windows'] ?? null) ? $rules['windows'] : [];
        $holdDays = $windows['hold_days'] ?? 0;
        $manualOutcomes = is_array($holds['manual_review_outcomes'] ?? null) ? $holds['manual_review_outcomes'] : [];

        if ((($holds['ambiguous_device'] ?? false) === true && $fixture->isAmbiguousDevice)
            || (is_int($holdDays) && $holdDays > 0)
            || in_array($fixture->outcome->value, $manualOutcomes, true)
        ) {
            $add(RewardPreviewReason::HoldRequired, 'Policy requires a hold before any decision; ambiguity is held rather than approved.');

            return $this->result(RewardPreviewDecision::Hold, $context, $reasons, $explanations);
        }

        $add(RewardPreviewReason::Ok, 'Eligible under the applicable frozen policy snapshot.');
        $add(RewardPreviewReason::LiveChecksDeferred, 'Live award uniqueness, cap counters, and cross-program pairwise stacking must still be enforced under locks at evaluation time.');

        return $this->result(RewardPreviewDecision::Eligible, $context, $reasons, $explanations);
    }

    /**
     * @param  list<RewardPreviewReason>  $reasons
     * @param  list<string>  $explanations
     */
    private function result(RewardPreviewDecision $decision, RewardPreviewContext $context, array $reasons, array $explanations): RewardPreviewResult
    {
        array_unshift($explanations, 'Fixture evidence only ('.RewardPreviewFixture::FIXTURE_LABEL.'): supplied for policy computation, not source integration.');

        return new RewardPreviewResult(
            decision: $decision,
            reasons: $reasons,
            explanations: $explanations,
            applicableVersionId: $context->versionId,
            acceptanceId: $context->acceptanceId,
        );
    }
}
