<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Affiliates\Actions\Conversions\ReverseAffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\States\ConversionStatus;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareEffectStatus;
use App\Jobs\Referrals\ReverseReferralRewardsForShareOutcomeJob;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use App\Support\ShareTracking\ShareTrackingFinance;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ReverseShareOutcomeAction
{
    use AsAction;

    public function __construct(
        private ReverseAffiliateConversion $reverseConversion,
    ) {}

    public function handle(
        string $businessKey,
        string $reason,
        ?User $actor = null,
        ?Request $request = null,
    ): ?ShareOutcomeCorrection {
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
            ReverseReferralRewardsForShareOutcomeJob::dispatch($businessKey, $reason)->afterCommit();
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

        $outcomeType = DawahShareOutcomeType::tryFrom($receipt->outcome_type);

        if (! $outcomeType instanceof DawahShareOutcomeType) {
            throw new InvalidArgumentException('Share outcome receipt carries an unknown outcome type.');
        }

        ShareOutcomeBusinessKey::parse($businessKey, $outcomeType);

        return DB::transaction(function () use ($businessKey, $reason, $actor): ?ShareOutcomeCorrection {
            $existing = ShareOutcomeCorrection::query()->where('business_key', $businessKey)->lockForUpdate()->first();

            if ($existing instanceof ShareOutcomeCorrection) {
                return $existing;
            }

            $effects = ShareEffectReceipt::query()
                ->where('source_id', $businessKey)
                ->whereIn('status', [ShareEffectStatus::Pending->value, ShareEffectStatus::Leased->value])
                ->lockForUpdate()
                ->get();

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

            $effects->each(function (ShareEffectReceipt $effect): void {
                if ($effect->isTerminal()) {
                    return;
                }

                $effect->forceFill([
                    'status' => ShareEffectStatus::Cancelled,
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                ])->save();
            });

            try {
                return DB::transaction(static fn (): ShareOutcomeCorrection => ShareOutcomeCorrection::query()->create([
                    'conversion_id' => $conversion->getKey(),
                    'business_key' => $businessKey,
                    'reason' => $reason,
                    'actor_type' => $actor?->getMorphClass(),
                    'actor_id' => $actor?->getKey(),
                    'occurred_at' => now(),
                ]), 1);
            } catch (QueryException $exception) {
                if (! in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true)) {
                    throw $exception;
                }

                return ShareOutcomeCorrection::query()->where('business_key', $businessKey)->firstOrFail();
            }
        });
    }
}
