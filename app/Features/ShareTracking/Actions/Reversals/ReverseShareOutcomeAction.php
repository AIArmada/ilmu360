<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\Reversals;

use AIArmada\Affiliates\Features\Conversions\Actions\ReverseAffiliateConversion;
use AIArmada\Affiliates\Features\Conversions\Models\AffiliateConversion;
use AIArmada\Affiliates\Features\Conversions\States\ConversionStatus;
use App\Features\Referrals\Api\ReferralOutcomeWorkflow;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\OutcomeCorrectionSnapshot;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Features\ShareTracking\Models\ShareSourceAttributionReceipt;
use App\Features\ShareTracking\Support\ShareTrackingFinance;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class ReverseShareOutcomeAction implements ShareOutcomeReversals
{
    public function __construct(
        private ReverseAffiliateConversion $reverseConversion,
        private EffectReceipts $effectReceipts,
        private OutcomeCorrections $outcomeCorrections,
        private ReferralOutcomeWorkflow $referrals,
    ) {}

    public function reverse(
        string $businessKey,
        string $reason,
        ?string $actorId = null,
    ): ?OutcomeCorrectionSnapshot {
        $businessKey = trim($businessKey);
        $reason = trim($reason);

        if ($businessKey === '' || mb_strlen($businessKey) > 191) {
            throw new InvalidArgumentException('Business key must be a non-empty bounded identity.');
        }

        if (! str_starts_with($businessKey, ShareOutcomeBusinessKey::NAMESPACE.':')) {
            throw new InvalidArgumentException('Only share-outcome namespaced facts can be reversed by this helper.');
        }

        if ($reason === '' || mb_strlen($reason) > 64) {
            throw new InvalidArgumentException('Correction reason must be a non-empty bounded value.');
        }

        try {
            $this->referrals->scheduleRewardReversal($businessKey, $reason);
        } catch (\Throwable $exception) {
            Log::warning('Referral reversal dispatch failed while correcting a share outcome.', [
                'business_key' => $businessKey,
                'reason' => $reason,
                'exception' => $exception,
            ]);
        }

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->first();

        if (! $receipt instanceof ShareSourceAttributionReceipt || ! $receipt->isAttributed()) {
            return null;
        }

        $outcomeType = ShareOutcomeType::tryFrom($receipt->outcome_type);

        if (! $outcomeType instanceof ShareOutcomeType) {
            throw new InvalidArgumentException('Share outcome receipt carries an unknown outcome type.');
        }

        ShareOutcomeBusinessKey::parse($businessKey, $outcomeType);

        return DB::transaction(function () use ($businessKey, $reason, $actorId): ?OutcomeCorrectionSnapshot {
            $existing = $this->outcomeCorrections->findByBusinessKeyUnderLock($businessKey);

            if ($existing instanceof OutcomeCorrectionSnapshot) {
                return $existing;
            }

            // Preserve the dispatcher's receipt-then-conversion lock order:
            // effects are locked before the conversion row is read.
            $this->effectReceipts->lockCancelableBySource($businessKey);

            $conversion = AffiliateConversion::query()
                ->where('idempotency_key', hash('sha256', $businessKey))
                ->lockForUpdate()
                ->first();

            if (! $conversion instanceof AffiliateConversion) {
                return null;
            }

            if (ShareTrackingFinance::isMonetaryConversion($conversion)) {
                throw new InvalidArgumentException('Monetary conversions require the authorized finance reversal workflow, not the zero-fact helper.');
            }

            $status = $conversion->status;
            $value = $status instanceof ConversionStatus
                ? $status->getValue()
                : (string) $status;

            if ($value !== 'reversed') {
                $this->reverseConversion->handle($conversion, $reason);
            }

            $this->effectReceipts->cancelPendingBySource($businessKey);

            $actorType = null;
            if ($actorId !== null) {
                $userClass = MorphModelResolver::resolve('user');
                $actorType = (new $userClass)->getMorphClass();
            }

            return $this->outcomeCorrections->ensure(
                businessKey: $businessKey,
                conversionId: (string) $conversion->getKey(),
                reason: $reason,
                actorType: $actorType,
                actorId: $actorId,
            );
        }, 3);
    }
}
