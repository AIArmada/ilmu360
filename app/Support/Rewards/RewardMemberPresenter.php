<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAppealDecision;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardAwardEntry;
use AIArmada\Rewards\Models\RewardBadgeGrant;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardEnrollmentAcceptance;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Redacted member-facing presenters for reward reads.
 *
 * Members see their own accounting (amounts, balances, own timestamps,
 * decision reasons) but never counterpart identity: no actor/sharer ids,
 * no raw business keys, no staff actor ids, no exact sensitive source
 * time (source dates are day-precision), and no unbounded metadata.
 */
final class RewardMemberPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function programSummary(RewardProgram $program, ?RewardEnrollment $enrollment): array
    {
        return [
            'id' => (string) $program->getKey(),
            'family_key' => $program->family_key,
            'title' => $program->title,
            'mode' => $program->mode->value,
            'status' => $program->status->value,
            'enrolled' => $enrollment instanceof RewardEnrollment,
            'enrolled_at' => self::iso($enrollment?->enrolled_at),
            'left_at' => self::iso($enrollment?->left_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function programDetail(
        RewardProgram $program,
        ?RewardProgramVersion $version,
        ?RewardEnrollment $enrollment,
        ?RewardEnrollmentAcceptance $acceptance,
    ): array {
        $rules = $version?->rules;
        $terms = $version?->terms;

        return [
            'id' => (string) $program->getKey(),
            'family_key' => $program->family_key,
            'title' => $program->title,
            'mode' => $program->mode->value,
            'status' => $program->status->value,
            'version' => $version instanceof RewardProgramVersion ? [
                'id' => (string) $version->getKey(),
                'version' => (int) $version->version,
                'effective_from' => self::iso($version->effective_from),
                'components' => self::componentSummaries(is_array($rules) ? ($rules['components'] ?? null) : null),
                'eligibility' => self::eligibilitySummary(is_array($rules) ? ($rules['eligibility'] ?? null) : null),
                'milestones' => self::milestoneSummaries(is_array($rules) ? ($rules['milestones'] ?? null) : null),
                'terms' => is_array($terms) ? $terms : null,
                'material_terms_hash' => (string) $version->material_terms_hash,
            ] : null,
            'enrollment' => $enrollment instanceof RewardEnrollment ? [
                'id' => (string) $enrollment->getKey(),
                'status' => $enrollment->status->value,
                'enrolled_at' => self::iso($enrollment->enrolled_at),
                'left_at' => self::iso($enrollment->left_at),
                'acceptance' => $acceptance instanceof RewardEnrollmentAcceptance ? [
                    'material_terms_hash' => (string) $acceptance->material_terms_hash,
                    'accepted_at' => self::iso($acceptance->accepted_at),
                    'accepted_via' => $acceptance->accepted_via,
                    'current' => $version instanceof RewardProgramVersion
                        && (string) $acceptance->material_terms_hash === (string) $version->material_terms_hash,
                ] : null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function awardSummary(RewardAward $award, ?RewardProgram $program): array
    {
        return [
            'id' => (string) $award->getKey(),
            'program' => $program instanceof RewardProgram ? [
                'id' => (string) $program->getKey(),
                'title' => $program->title,
                'family_key' => $program->family_key,
            ] : null,
            'component' => $award->component->value,
            'beneficiary_role' => $award->beneficiary_role->value,
            'status' => $award->status->value,
            'amount' => self::amountSummary($award->amount_snapshot),
            'source' => self::sourceSummary($award->source_snapshot),
            'decided_at' => self::iso(self::awardDecidedAt($award)),
        ];
    }

    /**
     * @param  iterable<RewardAwardEntry>  $entries
     * @return array<string, mixed>
     */
    public static function awardDetail(RewardAward $award, ?RewardProgram $program, iterable $entries, ?RewardAppeal $appeal): array
    {
        $detail = self::awardSummary($award, $program);

        $detail['timeline'] = [
            'held_at' => self::iso($award->held_at),
            'pending_review_at' => self::iso($award->pending_review_at),
            'approved_at' => self::iso($award->approved_at),
            'rejected_at' => self::iso($award->rejected_at),
            'fulfilled_at' => self::iso($award->fulfilled_at),
            'reversed_at' => self::iso($award->reversed_at),
            'corrected_at' => self::iso($award->corrected_at),
        ];

        $detail['reasons'] = [];

        foreach ($entries as $entry) {
            $detail['reasons'][] = [
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'decided_by' => self::decidedByLabel((string) $entry->actor_role),
                'reason' => $entry->reason,
                'decided_at' => self::iso($entry->decided_at),
            ];
        }

        $detail['appeal'] = $appeal instanceof RewardAppeal ? self::appealSummary($appeal) : null;

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    public static function appealSummary(RewardAppeal $appeal): array
    {
        return [
            'id' => (string) $appeal->getKey(),
            'award_id' => (string) $appeal->award_id,
            'status' => $appeal->status->value,
            'reason' => $appeal->reason,
            'window_ends_at' => self::iso($appeal->window_ends_at),
            'opened_at' => self::iso($appeal->opened_at),
            'in_review_at' => self::iso($appeal->in_review_at),
            'upheld_at' => self::iso($appeal->upheld_at),
            'overturned_at' => self::iso($appeal->overturned_at),
            'closed_at' => self::iso($appeal->closed_at),
        ];
    }

    /**
     * @param  iterable<RewardAppealDecision>  $decisions
     * @return array<string, mixed>
     */
    public static function appealDetail(RewardAppeal $appeal, iterable $decisions): array
    {
        $detail = self::appealSummary($appeal);
        $detail['decisions'] = [];

        foreach ($decisions as $decision) {
            $detail['decisions'][] = [
                'from_status' => $decision->from_status,
                'to_status' => $decision->to_status,
                'decided_by' => self::decidedByLabel((string) $decision->actor_role),
                'reason' => $decision->reason,
                'decided_at' => self::iso($decision->decided_at),
            ];
        }

        return $detail;
    }

    /**
     * Member-facing cash funds state. Only a confirmed payout is paid
     * money: submitted means sent-but-unverified, unknown means the
     * provider has not answered yet, and requested/approved mean the
     * payment is still being prepared. No consumer may present pending
     * funds as received.
     *
     * @return array{state: string, label: string, paid_at: ?string}
     */
    public static function payoutFundsState(string $status, ?string $confirmedAt): array
    {
        return match ($status) {
            'confirmed' => [
                'state' => 'paid',
                'label' => 'Paid',
                'paid_at' => $confirmedAt,
            ],
            'submitted' => [
                'state' => 'processing',
                'label' => 'Sent — waiting for provider confirmation',
                'paid_at' => null,
            ],
            'unknown' => [
                'state' => 'unknown',
                'label' => 'Being verified — funds are not confirmed yet',
                'paid_at' => null,
            ],
            'requested', 'approved' => [
                'state' => 'processing',
                'label' => 'Being prepared',
                'paid_at' => null,
            ],
            'failed' => [
                'state' => 'failed',
                'label' => 'Failed — the team will retry or contact you',
                'paid_at' => null,
            ],
            'cancelled' => [
                'state' => 'cancelled',
                'label' => 'Cancelled',
                'paid_at' => null,
            ],
            default => [
                'state' => 'unknown',
                'label' => 'Being verified — funds are not confirmed yet',
                'paid_at' => null,
            ],
        };
    }

    /**
     * Member-facing payee summary. Only the masked display value leaves
     * the boundary; the operator account handle never renders.
     *
     * @return array<string, mixed>
     */
    public static function payeeSummary(RewardPayee $payee): array
    {
        return [
            'id' => (string) $payee->getKey(),
            'method' => (string) $payee->method,
            'account_display' => (string) $payee->account_display,
            'status' => $payee->status->value,
            'verified_at' => self::iso($payee->verified_at),
            'rejected_at' => self::iso($payee->rejected_at),
            'superseded_at' => self::iso($payee->superseded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function badgeSummary(RewardBadgeGrant $grant): array
    {
        return [
            'id' => (string) $grant->getKey(),
            'family_key' => $grant->family_key,
            'badge_key' => $grant->badge_key,
            'status' => $grant->status->value,
            'granted_at' => self::iso($grant->granted_at),
            'revoked_at' => self::iso($grant->revoked_at),
            'revoke_reason' => $grant->revoke_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function amountSummary(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $allowed = ['type', 'points', 'unit', 'minor_units', 'currency', 'badge_key', 'benefit_key', 'template', 'expires_in_days'];

        $summary = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $snapshot) && (is_scalar($snapshot[$key]) || $snapshot[$key] === null)) {
                $summary[$key] = $snapshot[$key];
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private static function sourceSummary(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return ['outcome' => null, 'outcome_label' => null, 'source_date' => null];
        }

        $outcome = (string) ($snapshot['outcome'] ?? '');

        if ($outcome === '' || ! in_array($outcome, config('rewards.outcomes', []), true)) {
            $outcome = null;
        }

        $date = null;

        if (is_string($snapshot['occurred_at'] ?? null) && $snapshot['occurred_at'] !== '') {
            try {
                $date = CarbonImmutable::parse($snapshot['occurred_at'])->setTimezone('UTC')->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'outcome' => $outcome,
            'outcome_label' => $outcome === null ? null : self::outcomeLabel($outcome),
            'source_date' => $date,
        ];
    }

    private static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'signup' => 'New member signup',
            'event_registration' => 'Event registration',
            'event_checkin' => 'Event attendance',
            'event_submission' => 'Event submission',
            'event_save' => 'Saved event',
            'event_going' => 'Marked going',
            'institution_follow' => 'Institution follow',
            'person_follow' => 'Person follow',
            'series_follow' => 'Series follow',
            'reference_follow' => 'Reference follow',
            'saved_search_created' => 'Saved search',
            default => $outcome,
        };
    }

    private static function awardDecidedAt(RewardAward $award): mixed
    {
        return match ($award->status) {
            RewardAwardStatus::Held => $award->held_at,
            RewardAwardStatus::PendingReview => $award->pending_review_at,
            RewardAwardStatus::Approved => $award->approved_at,
            RewardAwardStatus::Rejected => $award->rejected_at,
            RewardAwardStatus::Fulfilled => $award->fulfilled_at,
            RewardAwardStatus::Reversed => $award->reversed_at,
            RewardAwardStatus::Corrected => $award->corrected_at,
        };
    }

    private static function decidedByLabel(string $role): string
    {
        return match ($role) {
            'staff', 'admin' => 'Review team',
            'system' => 'Automatic review',
            'member' => 'You',
            default => 'Review team',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function componentSummaries(mixed $components): array
    {
        if (! is_array($components)) {
            return [];
        }

        $summaries = [];

        foreach ($components as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }

            $summaries[] = self::amountSummary($entry);
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function eligibilitySummary(mixed $eligibility): array
    {
        if (! is_array($eligibility)) {
            return [];
        }

        $outcomes = [];

        $allowed = config('rewards.outcomes', []);

        foreach ((array) ($eligibility['outcomes'] ?? []) as $outcome) {
            $parsed = (string) $outcome;

            if ($parsed !== '' && in_array($parsed, is_array($allowed) ? $allowed : [], true)) {
                $outcomes[] = ['outcome' => $parsed, 'label' => self::outcomeLabel($parsed)];
            }
        }

        return [
            'outcomes' => $outcomes,
            'min_verification' => is_string($eligibility['min_verification'] ?? null) ? $eligibility['min_verification'] : null,
            'first_only' => (bool) ($eligibility['first_only'] ?? false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function milestoneSummaries(mixed $milestones): array
    {
        if (! is_array($milestones)) {
            return [];
        }

        $summaries = [];

        foreach ($milestones as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }

            $summaries[] = [
                'key' => $definition['key'],
                'target_count' => $definition['target_count'] ?? null,
                'window_days' => $definition['window_days'] ?? null,
                'reward' => self::amountSummary($definition['reward'] ?? null),
            ];
        }

        return $summaries;
    }

    private static function iso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->setTimezone('UTC')->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value)->setTimezone('UTC')->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
