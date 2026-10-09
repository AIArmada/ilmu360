<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use App\Enums\Rewards\RewardAwardStatus;
use App\Models\RewardAward;
use App\Models\RewardMilestoneContribution;
use App\Models\ShareOutcomeCorrection;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Propagate source corrections and confirmed fraud onto live awards.
 *
 * Source reversals (event un-checkin, registration cancelled, follow
 * withdrawn…) land as ShareOutcomeCorrection rows, and investigators land
 * confirmed AffiliateFraudSignals on conversions. Evaluation-time reads
 * already refuse those keys; this sweep closes the loop for awards that
 * were decided BEFORE the correction arrived, matching the queued
 * recheck semantics exactly: held and pending-review awards reject,
 * approved awards reverse, and milestone contributions invalidate.
 * Fulfilled awards — which the queued job deliberately never touches —
 * reverse through the recovery-aware fulfillment connector
 * (receivable/dispute recoveries open where value already moved). Every
 * applied correction appends ledger entries with the correction
 * evidence, so member timelines, balances, and admin tables all show
 * the adjusted state.
 *
 * The sweep is explicit and never silent: it returns every reversed and
 * rejected award, every opened recovery, every invalidated contribution,
 * every skip, and every per-award error. Re-runs converge (settled work
 * skips), so the scheduled run, an operator rerun, and the queued job
 * all compose safely.
 *
 * @return array{reversed: list<string>, rejected: list<string>, recoveries: list<string>, contributions: int, skipped: list<string>, errors: array<string, string>}
 */
final readonly class ApplyRewardSourceCorrectionsAction
{
    use AsAction;

    /**
     * @return array{reversed: list<string>, rejected: list<string>, recoveries: list<string>, contributions: int, skipped: list<string>, errors: array<string, string>}
     */
    public function handle(
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
        int $limit = 200,
    ): array {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if (! in_array($actorRole, ['staff', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('Applying source corrections requires a staff/admin/system identity.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Correction sweep limit must be 1..1000.');
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $report = ['reversed' => [], 'rejected' => [], 'recoveries' => [], 'contributions' => 0, 'skipped' => [], 'errors' => []];

        foreach ($this->taintedKeys($limit) as $businessKey => $cause) {
            $awards = RewardAward::query()
                ->where('source_business_key', $businessKey)
                ->orderBy('id')
                ->get();

            foreach ($awards as $award) {
                $awardId = (string) $award->getKey();

                if (in_array($award->status, [
                    RewardAwardStatus::Reversed,
                    RewardAwardStatus::Rejected,
                    RewardAwardStatus::Corrected,
                ], true)) {
                    $report['skipped'][] = $awardId;

                    continue;
                }

                try {
                    // Same routing as the queued recheck: unapproved work
                    // rejects, approved work reverses, fulfilled work goes
                    // through recovery.
                    $to = match ($award->status) {
                        RewardAwardStatus::Held,
                        RewardAwardStatus::PendingReview => RewardAwardStatus::Rejected,
                        default => RewardAwardStatus::Reversed,
                    };

                    if ($award->status === RewardAwardStatus::Fulfilled) {
                        $outcome = ReverseRewardFulfillmentAction::run(
                            $awardId, $actorType, $actorId, $actorRole,
                            $cause['reason'],
                            ['source_correction' => $cause['evidence']],
                            $decided,
                        );

                        foreach ($outcome['recovery_ids'] as $recoveryId) {
                            $report['recoveries'][] = (string) $recoveryId;
                        }
                    } else {
                        app(TransitionRewardAwardAction::class)->handle(
                            $awardId, $to, $actorType, $actorId, $actorRole,
                            $cause['reason'],
                            ['source_correction' => $cause['evidence']],
                            null, $decided,
                        );
                    }

                    $report[$to === RewardAwardStatus::Reversed ? 'reversed' : 'rejected'][] = $awardId;
                } catch (InvalidArgumentException $exception) {
                    $report['errors'][$awardId] = $exception->getMessage();
                }
            }

            $report['contributions'] += $this->invalidateContributions($businessKey, $actorType, $actorId, $actorRole, $cause['reason'], $decided);
        }

        return $report;
    }

    private function invalidateContributions(
        string $businessKey,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        string $reason,
        CarbonImmutable $decided,
    ): int {
        $contributions = RewardMilestoneContribution::query()
            ->where('source_business_key', $businessKey)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();

        $count = 0;

        foreach ($contributions as $contribution) {
            try {
                app(InvalidateRewardMilestoneContributionAction::class)->handle(
                    $contribution, $actorType, $actorId, $actorRole, $reason, $decided,
                );
                $count++;
            } catch (InvalidArgumentException) {
                // A concurrent invalidation won; the contribution is
                // settled either way.
            }
        }

        return $count;
    }

    /**
     * Tainted source keys from both correction channels: explicit
     * ShareOutcomeCorrection rows, and conversions carrying a confirmed
     * fraud signal (linked back to the business key through the
     * conversion idempotency key).
     *
     * @return array<string, array{reason: string, evidence: array<string, mixed>}>
     */
    private function taintedKeys(int $limit): array
    {
        $tainted = [];

        $corrections = ShareOutcomeCorrection::query()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($corrections as $correction) {
            $key = (string) $correction->business_key;

            $tainted[$key] ??= [
                'reason' => 'source-correction:'.(string) $correction->reason,
                'evidence' => [
                    'correction_id' => (string) $correction->getKey(),
                    'business_key' => $key,
                ],
            ];
        }

        $remaining = $limit - count($tainted);

        if ($remaining > 0) {
            $conversionTable = (new AffiliateConversion)->getTable();
            $fraudTable = (new AffiliateFraudSignal)->getTable();

            $rows = DB::table("{$fraudTable} as f")
                ->join("{$conversionTable} as c", 'c.id', '=', 'f.conversion_id')
                ->where('f.status', FraudSignalStatus::Confirmed->value)
                ->whereNotNull('c.idempotency_key')
                ->orderBy('f.detected_at')
                ->orderBy('f.id')
                ->limit($remaining)
                ->get(['f.id as fraud_id', 'c.idempotency_key as idempotency_key']);

            $byDigest = [];

            foreach ($rows as $row) {
                $byDigest[(string) $row->idempotency_key] = (string) $row->fraud_id;
            }

            if ($byDigest !== []) {
                // Only live awards can need sweeping; terminal awards
                // never match, which also bounds the digest scan.
                $keys = RewardAward::query()
                    ->whereNotNull('source_business_key')
                    ->whereNotIn('status', [
                        RewardAwardStatus::Reversed->value,
                        RewardAwardStatus::Rejected->value,
                        RewardAwardStatus::Corrected->value,
                    ])
                    ->distinct()
                    ->pluck('source_business_key')
                    ->all();

                foreach ($keys as $key) {
                    $key = (string) $key;

                    if (isset($byDigest[hash('sha256', $key)]) && ! isset($tainted[$key])) {
                        $tainted[$key] = [
                            'reason' => 'confirmed-source-fraud',
                            'evidence' => [
                                'fraud_signal_id' => $byDigest[hash('sha256', $key)],
                                'business_key' => $key,
                            ],
                        ];
                    }
                }
            }
        }

        return $tainted;
    }
}
