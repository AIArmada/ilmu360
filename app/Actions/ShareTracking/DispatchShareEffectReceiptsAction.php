<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Affiliates\States\ReversedConversion;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Events\Models\EventRegistration;
use App\Actions\Events\Commerce\ReverseTicketAffiliateCommissionAction;
use App\Features\ShareTracking\Api\EffectReceiptSnapshot;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Models\ShareOutcomeCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DispatchShareEffectReceiptsAction
{
    use AsAction;

    /**
     * Effect types this dispatcher owns: downstream outcome projection
     * effects (conversion/link payloads). Every other effect type
     * belongs to its own named consumer and is never selected here —
     * the generic dispatcher cannot steal foreign receipts.
     *
     * @var list<string>
     */
    public const array OWNED_EFFECT_TYPES = ['projection', 'ticket_affiliate_reversal'];

    public function __construct(
        private ReverseTicketAffiliateCommissionAction $reverseTicketAffiliateCommission,
        private EffectReceipts $effectReceipts,
    ) {}

    public function handle(int $limit = 50, ?string $workerId = null): int
    {
        $limit = max(1, min($limit, 200));
        $workerId ??= 'dispatch-'.gethostname().'-'.getmypid();
        $processed = 0;

        $receipts = $this->effectReceipts->claimableBatch(
            ownedEffectTypes: self::OWNED_EFFECT_TYPES,
            limit: $limit,
        );

        foreach ($receipts as $receipt) {
            if ($this->processReceipt($receipt->id, $workerId)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function processReceipt(string $receiptId, string $workerId): bool
    {
        $leaseToken = (string) Str::uuid();

        $claimed = $this->effectReceipts->claim(
            receiptId: $receiptId,
            ownedEffectTypes: self::OWNED_EFFECT_TYPES,
            workerId: $workerId,
            leaseToken: $leaseToken,
        );

        if (! $claimed instanceof EffectReceiptSnapshot) {
            return false;
        }

        return DB::transaction(function () use ($receiptId, $workerId, $leaseToken): bool {
            $locked = $this->effectReceipts->lockLease(
                receiptId: $receiptId,
                workerId: $workerId,
                leaseToken: $leaseToken,
            );

            if (! $locked instanceof EffectReceiptSnapshot) {
                return false;
            }

            if ($locked->effectType === 'ticket_affiliate_reversal') {
                return $this->processDeferredTicketReversal($locked, $workerId, $leaseToken);
            }

            $conversionId = $locked->payload['conversion_id'] ?? null;
            $linkId = $locked->payload['link_id'] ?? null;

            $conversion = is_string($conversionId) && $conversionId !== ''
                ? AffiliateConversion::query()->whereKey($conversionId)->lockForUpdate()->first()
                : null;

            if (! $conversion instanceof AffiliateConversion) {
                $this->effectReceipts->markFailed($receiptId, $workerId, $leaseToken);

                return true;
            }

            $status = $conversion->status;
            $value = $status instanceof ConversionStatus
                ? $status->getValue()
                : (string) $status;

            $correctionExists = ShareOutcomeCorrection::query()
                ->where('business_key', $locked->sourceId)
                ->lockForUpdate()
                ->exists();

            if (in_array($value, ['rejected', 'reversed'], true) || $correctionExists) {
                $this->effectReceipts->markCancelled($receiptId, $workerId, $leaseToken);

                return true;
            }

            if (! is_string($linkId) || $linkId === '') {
                $this->effectReceipts->markCancelled($receiptId, $workerId, $leaseToken);

                return true;
            }

            $link = AffiliateLink::query()->whereKey($linkId)->lockForUpdate()->first();

            if (! $link instanceof AffiliateLink) {
                $this->effectReceipts->markCancelled($receiptId, $workerId, $leaseToken);

                return true;
            }

            $link->incrementConversions();

            $this->effectReceipts->markSucceeded($receiptId, $workerId, $leaseToken);

            return true;
        });
    }

    private function processDeferredTicketReversal(
        EffectReceiptSnapshot $receipt,
        string $workerId,
        string $leaseToken,
    ): bool {
        $registrationId = $receipt->payload['registration_id'] ?? null;
        $conversionId = $receipt->payload['conversion_id'] ?? null;
        $reason = $receipt->payload['reason'] ?? null;

        $registration = is_string($registrationId) && $registrationId !== ''
            ? EventRegistration::query()->whereKey($registrationId)->first()
            : null;

        if (! $registration instanceof EventRegistration || ! is_string($reason) || $reason === '') {
            $this->effectReceipts->markFailed($receipt->id, $workerId, $leaseToken);

            return true;
        }

        $this->reverseTicketAffiliateCommission->handle($registration, $reason);

        $conversion = is_string($conversionId) && $conversionId !== ''
            ? AffiliateConversion::query()->withoutGlobalScope(OwnerScope::class)->whereKey($conversionId)->lockForUpdate()->first()
            : null;

        if ($conversion instanceof AffiliateConversion
            && ($conversion->status instanceof ConversionStatus
                ? $conversion->status->equals(ReversedConversion::class)
                : (string) $conversion->status === 'reversed')) {
            $this->effectReceipts->markSucceeded($receipt->id, $workerId, $leaseToken);

            return true;
        }

        if ($conversion instanceof AffiliateConversion
            && $this->reverseTicketAffiliateCommission->hasOpenPayoutReservation($conversion)) {
            $this->effectReceipts->releaseToPending($receipt->id, $workerId, $leaseToken);

            return true;
        }

        $this->effectReceipts->markFailed($receipt->id, $workerId, $leaseToken);

        return true;
    }
}
