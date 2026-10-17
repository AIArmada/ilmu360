<?php

namespace App\Actions\Events;

use AIArmada\Affiliates\Actions\Conversions\ReverseAffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\States\CancelledPayout;
use AIArmada\Affiliates\States\CompletedPayout;
use AIArmada\Affiliates\States\FailedPayout;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleParser;
use AIArmada\Events\Models\EventRegistration as PackageEventRegistration;
use App\Enums\ShareEffectStatus;
use App\Models\ShareEffectReceipt;

final readonly class ReverseTicketAffiliateCommissionAction
{
    public function __construct(
        private ReverseAffiliateConversion $reverseAffiliateConversion,
    ) {}

    public function handle(PackageEventRegistration $registration, string $reason): ?AffiliateConversion
    {
        $orderId = $registration->external_order_id;

        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        $externalReference = sprintf('ticket:%s:registration:%s', $orderId, $registration->getKey());

        // Registration UUIDs are globally unique. This exact-key read is
        // followed by order/registration snapshot checks and owner-scoped
        // reversal; it does not expose a tenant-wide conversion query.
        $conversion = AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('external_reference', $externalReference)
            ->where('conversion_type', 'purchase')
            ->first();

        if (! $conversion instanceof AffiliateConversion
            || (string) data_get($conversion->metadata ?? [], 'order_id', '') !== $orderId
            || (string) data_get($conversion->metadata ?? [], 'registration_id', '') !== (string) $registration->getKey()) {
            return null;
        }

        if ($this->hasOpenPayoutReservation($conversion)) {
            $this->deferReversal($conversion, $registration, $reason);

            return null;
        }

        if ($conversion->owner_type === null && $conversion->owner_id === null) {
            return $this->reverseAffiliateConversion->handle($conversion, $reason);
        }

        if (! is_string($conversion->owner_type) || ! is_string($conversion->owner_id)) {
            return null;
        }

        $owner = OwnerTupleParser::fromTypeAndId($conversion->owner_type, $conversion->owner_id)->toOwnerModel();

        /** @var AffiliateConversion $reversal */
        $reversal = OwnerContext::withOwner(
            $owner,
            fn (): AffiliateConversion => $this->reverseAffiliateConversion->handle($conversion, $reason),
        );

        return $reversal;
    }

    public function hasOpenPayoutReservation(AffiliateConversion $conversion): bool
    {
        if (! is_string($conversion->affiliate_payout_id) || $conversion->affiliate_payout_id === '') {
            return false;
        }

        $payout = AffiliatePayout::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($conversion->affiliate_payout_id)
            ->first();

        if (! $payout instanceof AffiliatePayout) {
            return false;
        }

        return ! $payout->status->equals(CompletedPayout::class)
            && ! $payout->status->equals(FailedPayout::class)
            && ! $payout->status->equals(CancelledPayout::class);
    }

    private function deferReversal(
        AffiliateConversion $conversion,
        PackageEventRegistration $registration,
        string $reason,
    ): void {
        $conversionId = (string) $conversion->getKey();

        ShareEffectReceipt::query()->firstOrCreate(
            ['effect_key' => 'ticket-affiliate-reversal:'.hash('sha256', $conversionId)],
            [
                'source_type' => 'event_registration',
                'source_id' => (string) $registration->getKey(),
                'effect_type' => 'ticket_affiliate_reversal',
                'status' => ShareEffectStatus::Pending,
                'payload' => [
                    'conversion_id' => $conversionId,
                    'registration_id' => (string) $registration->getKey(),
                    'order_id' => (string) $registration->external_order_id,
                    'reason' => $reason,
                ],
            ],
        );
    }
}
