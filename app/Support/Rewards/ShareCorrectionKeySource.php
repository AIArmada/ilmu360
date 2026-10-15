<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Rewards\Contracts\RewardCorrectionKeySource;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Models\RewardAward;
use App\Models\ShareOutcomeCorrection;
use Illuminate\Support\Facades\DB;

/**
 * Tainted source keys from both correction channels: explicit
 * ShareOutcomeCorrection rows, and conversions carrying a confirmed
 * fraud signal (linked back to the business key through the
 * conversion idempotency key).
 */
final class ShareCorrectionKeySource implements RewardCorrectionKeySource
{
    public function __construct(private readonly RewardSourceFactReader $sourceFactReader) {}

    public function taintedKeys(int $limit): array
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

        $remaining = $limit - count($tainted);

        if ($remaining > 0) {
            $activeActivityKeys = RewardAward::query()
                ->whereNotNull('source_business_key')
                ->where('source_business_key', 'like', 'activity:%')
                ->whereNotIn('status', [
                    RewardAwardStatus::Reversed->value,
                    RewardAwardStatus::Rejected->value,
                    RewardAwardStatus::Corrected->value,
                ])
                ->distinct()
                ->orderBy('source_business_key')
                ->pluck('source_business_key');

            foreach ($activeActivityKeys as $businessKey) {
                $businessKey = (string) $businessKey;

                if (isset($tainted[$businessKey])) {
                    continue;
                }

                $cause = $this->activitySourceCause($businessKey);

                if ($cause === null) {
                    continue;
                }

                $tainted[$businessKey] = $cause;

                if (count($tainted) >= $limit) {
                    break;
                }
            }
        }

        $remaining = $limit - count($tainted);

        if ($remaining > 0) {
            $activeReferralKeys = RewardAward::query()
                ->whereNotNull('source_business_key')
                ->where('source_business_key', 'like', 'referrals:qualified:%')
                ->whereNotIn('status', [
                    RewardAwardStatus::Reversed->value,
                    RewardAwardStatus::Rejected->value,
                    RewardAwardStatus::Corrected->value,
                ])
                ->distinct()
                ->orderBy('source_business_key')
                ->pluck('source_business_key');

            foreach ($activeReferralKeys as $businessKey) {
                $businessKey = (string) $businessKey;

                if (isset($tainted[$businessKey])) {
                    continue;
                }

                $cause = $this->referralSourceCause($businessKey);

                if ($cause === null) {
                    continue;
                }

                $tainted[$businessKey] = $cause;

                if (count($tainted) >= $limit) {
                    break;
                }
            }
        }

        $keys = [];

        foreach ($tainted as $businessKey => $cause) {
            $keys[] = [
                'business_key' => $businessKey,
                'reason' => $cause['reason'],
                'evidence' => $cause['evidence'],
            ];
        }

        return $keys;
    }

    public function causeFor(string $businessKey): ?array
    {
        $correction = ShareOutcomeCorrection::query()
            ->where('business_key', $businessKey)
            ->first();

        if ($correction instanceof ShareOutcomeCorrection) {
            return [
                'reason' => 'source-correction:'.(string) $correction->reason,
                'evidence' => [
                    'correction_id' => (string) $correction->getKey(),
                    'business_key' => $businessKey,
                ],
            ];
        }

        $conversionTable = (new AffiliateConversion)->getTable();
        $fraudTable = (new AffiliateFraudSignal)->getTable();

        $fraudId = DB::table("{$fraudTable} as f")
            ->join("{$conversionTable} as c", 'c.id', '=', 'f.conversion_id')
            ->where('f.status', FraudSignalStatus::Confirmed->value)
            ->where('c.idempotency_key', hash('sha256', $businessKey))
            ->orderBy('f.detected_at')
            ->orderBy('f.id')
            ->value('f.id');

        if ($fraudId === null) {
            return $this->sourceCause($businessKey);
        }

        return [
            'reason' => 'confirmed-source-fraud',
            'evidence' => [
                'fraud_signal_id' => (string) $fraudId,
                'business_key' => $businessKey,
            ],
        ];
    }

    /**
     * @return array{reason: string, evidence: array<string, mixed>}|null
     */
    private function activitySourceCause(string $businessKey): ?array
    {
        if (! str_starts_with($businessKey, 'activity:')) {
            return null;
        }

        $fact = $this->sourceFactReader->read($businessKey);

        if ($fact !== null && $fact->isValid && ! $fact->isCancelled) {
            return null;
        }

        if ($fact === null) {
            return [
                'reason' => 'activity-source-invalidated',
                'evidence' => [
                    'business_key' => $businessKey,
                    'source_missing' => true,
                    'source_cancelled' => false,
                    'source_valid' => false,
                ],
            ];
        }

        return [
            'reason' => 'activity-source-invalidated',
            'evidence' => [
                'business_key' => $businessKey,
                'source_missing' => false,
                'source_cancelled' => $fact->isCancelled,
                'source_valid' => $fact->isValid,
            ],
        ];
    }

    /**
     * @return array{reason: string, evidence: array<string, mixed>}|null
     */
    private function referralSourceCause(string $businessKey): ?array
    {
        if (! str_starts_with($businessKey, 'referrals:qualified:')) {
            return null;
        }

        $fact = $this->sourceFactReader->read($businessKey);

        if ($fact !== null && $fact->isValid && ! $fact->isCancelled) {
            return null;
        }

        $sourceCancelled = $fact !== null && $fact->isCancelled;
        $sourceValid = $fact !== null && $fact->isValid;

        return [
            'reason' => 'referral-source-invalidated',
            'evidence' => [
                'business_key' => $businessKey,
                'source_missing' => $fact === null,
                'source_cancelled' => $sourceCancelled,
                'source_valid' => $sourceValid,
            ],
        ];
    }

    /**
     * @return array{reason: string, evidence: array<string, mixed>}|null
     */
    private function sourceCause(string $businessKey): ?array
    {
        if (str_starts_with($businessKey, 'activity:')) {
            return $this->activitySourceCause($businessKey);
        }

        return $this->referralSourceCause($businessKey);
    }
}
