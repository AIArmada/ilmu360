<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

/**
 * Golden reason codes for deterministic qualification preview. Order is
 * stable: the engine records reasons in fixed gate order so identical inputs
 * always produce identical output.
 */
enum RewardPreviewReason: string
{
    case Ok = 'ok';
    case ProgramNotActive = 'program_not_active';
    case VersionMissing = 'version_missing';
    case OutcomeNotAllowlisted = 'outcome_not_allowlisted';
    case CategoryNotAllowlisted = 'category_not_allowlisted';
    case VerificationInsufficient = 'verification_insufficient';
    case RecurringExcluded = 'recurring_excluded';
    case SelfCreditExcluded = 'self_credit_excluded';
    case GuestUnclaimed = 'guest_unclaimed';
    case EnrollmentMissing = 'enrollment_missing';
    case EnrollmentLeft = 'enrollment_left';
    case AcceptanceMissing = 'acceptance_missing';
    case AcceptanceStaleMaterialChange = 'acceptance_stale_material_change';
    case AcceptedAfterDecision = 'accepted_after_decision';
    case OutsidePreclaimLookback = 'outside_preclaim_lookback';
    case LateAttributionRefused = 'late_attribution_refused';
    case EvidenceAfterDecision = 'evidence_after_decision';
    case ComponentNotEnabled = 'component_not_enabled';
    case HoldRequired = 'hold_required';
    case StackingConflict = 'stacking_conflict';
    case SourceBotExcluded = 'source_bot_excluded';
    case SourceCancelled = 'source_cancelled';
    case SourceInvalid = 'source_invalid';
    case LiveChecksDeferred = 'live_checks_deferred';
}
