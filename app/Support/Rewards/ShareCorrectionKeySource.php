<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Rewards\Contracts\RewardCorrectionKeySource;
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
            return null;
        }

        return [
            'reason' => 'confirmed-source-fraud',
            'evidence' => [
                'fraud_signal_id' => (string) $fraudId,
                'business_key' => $businessKey,
            ],
        ];
    }
}
