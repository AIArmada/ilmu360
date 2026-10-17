<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateProgram;
use AIArmada\Affiliates\Models\AffiliateProgramMembership;
use AIArmada\Cart\Cart;
use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleParser;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use App\Models\Registration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record commercial ticket commissions from a checkout's frozen attribution.
 *
 * Ticket commission value is the paid order-item net after discounts and
 * before tax, allocated evenly by registration with deterministic remainders.
 */
final readonly class RecordTicketAffiliateCommissionsAction
{
    public function __construct(
        private CartManagerInterface $cartManager,
        private RecordAffiliateConversion $recordAffiliateConversion,
    ) {}

    /**
     * @param  array<int, string>  $registrationIds
     * @return list<string>
     */
    public function handle(CheckoutSession $session, array $registrationIds): array
    {
        $order = $session->order;

        if (! $order instanceof Order || ! $order->isPaid() || (int) $order->grand_total <= 0) {
            return [];
        }

        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];

        if ((string) data_get($orderMetadata, 'checkout_session_id', '') !== (string) $session->getKey()) {
            return [];
        }

        $ownerType = is_string($order->owner_type) ? $order->owner_type : null;
        $ownerId = is_string($order->owner_id) ? $order->owner_id : null;

        return DB::transaction(function () use ($ownerType, $ownerId, $order, $orderMetadata, $registrationIds, $session): array {
            $record = fn (): array => $this->recordWithinOwnerScope(
                order: $order,
                orderMetadata: $orderMetadata,
                session: $session,
                registrationIds: $registrationIds,
            );

            if ($ownerType === null && $ownerId === null) {
                return $record();
            }

            if ($ownerType === null || $ownerId === null) {
                return [];
            }

            $owner = OwnerTupleParser::fromTypeAndId($ownerType, $ownerId)->toOwnerModel();

            /** @var list<string> $recorded */
            $recorded = OwnerContext::withOwner($owner, $record);

            return $recorded;
        });
    }

    /**
     * @param  array<string, mixed>  $orderMetadata
     * @param  array<int, string>  $registrationIds
     * @return list<string>
     */
    private function recordWithinOwnerScope(
        Order $order,
        array $orderMetadata,
        CheckoutSession $session,
        array $registrationIds,
    ): array {
        $frozen = data_get($orderMetadata, 'payment_data.affiliate_commission');

        if (! is_array($frozen) || ($frozen['strategy'] ?? null) !== 'registration_lines') {
            return [];
        }

        if (($frozen['commercial_purpose'] ?? false) !== true) {
            return [];
        }

        $attributionId = $frozen['attribution_id'] ?? null;
        $affiliateId = $frozen['affiliate_id'] ?? null;
        $programId = $frozen['affiliate_program_id'] ?? null;

        if (! is_string($attributionId) || ! Str::isUuid($attributionId)
            || ! is_string($affiliateId) || ! Str::isUuid($affiliateId)
            || ! is_string($programId) || ! Str::isUuid($programId)) {
            return [];
        }

        $attribution = AffiliateAttribution::query()
            ->whereKey($attributionId)
            ->where('affiliate_id', $affiliateId)
            ->where('affiliate_program_id', $programId)
            ->where('owner_type', $frozen['owner_type'] ?? null)
            ->where('owner_id', $frozen['owner_id'] ?? null)
            ->first();

        if (! $attribution instanceof AffiliateAttribution) {
            return [];
        }

        $affiliate = $attribution->affiliate;
        $program = AffiliateProgram::query()->whereKey($programId)->first();
        $membership = AffiliateProgramMembership::query()
            ->where('affiliate_id', $affiliateId)
            ->where('program_id', $programId)
            ->first();

        if ($affiliate === null || ! $affiliate->isCommercialPurpose()
            || ! $affiliate->isActive() || $affiliate->isPaused() || $affiliate->isDeactivated()
            || ! $program instanceof AffiliateProgram || ! $program->isActive()
            || ! $membership instanceof AffiliateProgramMembership || ! $membership->isActive()) {
            return [];
        }

        $actor = data_get($orderMetadata, 'payment_data.checkout_actor');

        if (! is_array($actor)
            || ! is_string($actor['type'] ?? null)
            || ! is_string($actor['id'] ?? null)
            || $actor['id'] === '') {
            return [];
        }

        if ($actor['type'] === $affiliate->owner_type
            && (string) ($actor['id'] ?? '') === (string) $affiliate->owner_id) {
            return [];
        }

        $cartId = data_get($orderMetadata, 'cart_id');

        if (! is_string($cartId) || $cartId === '') {
            return [];
        }

        $cart = $this->cartManager->getById($cartId);

        if (! $cart instanceof Cart || ! $cart->exists()) {
            return [];
        }

        $registrationIds = array_values(array_unique(array_filter(
            $registrationIds,
            static fn (string $registrationId): bool => Str::isUuid($registrationId),
        )));

        if ($registrationIds === []) {
            return [];
        }

        $registrations = Registration::query()
            ->whereIn('id', $registrationIds)
            ->where('external_order_id', $order->getKey())
            ->with('items')
            ->get();

        $lineRegistrationIds = [];
        foreach ($registrations as $registration) {
            $lineIds = $registration->items
                ->filter(static fn (EventRegistrationItem $item): bool => $item->external_order_item_type === OrderItem::class
                    && is_string($item->external_order_item_id)
                )
                ->pluck('external_order_item_id')
                ->unique()
                ->values();

            if ($lineIds->count() !== 1) {
                continue;
            }

            $lineRegistrationIds[(string) $lineIds->first()][] = (string) $registration->getKey();
        }

        if ($lineRegistrationIds === []) {
            return [];
        }

        $orderItems = OrderItem::query()
            ->where('order_id', $order->getKey())
            ->whereIn('id', array_keys($lineRegistrationIds))
            ->get()
            ->keyBy(fn (OrderItem $item): string => (string) $item->getKey());

        $occurredAt = $order->paid_at instanceof CarbonInterface ? $order->paid_at : now();
        $recorded = [];

        foreach ($lineRegistrationIds as $orderItemId => $lineRegistrations) {
            $orderItem = $orderItems->get((string) $orderItemId);

            if (! $orderItem instanceof OrderItem || count($lineRegistrations) !== (int) $orderItem->quantity) {
                continue;
            }

            sort($lineRegistrations, SORT_STRING);

            $lineNet = max(
                0,
                ((int) $orderItem->unit_price * (int) $orderItem->quantity)
                    - (int) ($orderItem->discount_amount ?? 0),
            );

            if ($lineNet === 0) {
                continue;
            }

            $baseAmount = intdiv($lineNet, count($lineRegistrations));
            $remainder = $lineNet % count($lineRegistrations);

            foreach ($lineRegistrations as $index => $registrationId) {
                $netAmount = $baseAmount + ($index < $remainder ? 1 : 0);

                if ($netAmount <= 0) {
                    continue;
                }

                $externalReference = sprintf('ticket:%s:registration:%s', $order->getKey(), $registrationId);
                $conversion = $this->recordAffiliateConversion->recordCommercialForFrozenAttribution(
                    $cart,
                    $attribution,
                    [
                        'idempotency_key' => hash('sha256', $externalReference),
                        'affiliate_id' => $affiliateId,
                        'affiliate_program_id' => $programId,
                        'external_reference' => $externalReference,
                        'conversion_type' => 'purchase',
                        'subtotal' => $netAmount,
                        'total' => $netAmount,
                        'commission_currency' => (string) ($orderItem->currency ?: $order->currency),
                        'subject_type' => Registration::class,
                        'subject_key' => $registrationId,
                        'subject_id' => $registrationId,
                        'source_ref' => $externalReference,
                        'origin' => 'event_ticket_checkout',
                        'occurred_at' => $occurredAt,
                        'metadata' => [
                            'order_id' => (string) $order->getKey(),
                            'order_item_id' => (string) $orderItem->getKey(),
                            'registration_id' => $registrationId,
                            'commission_basis' => 'paid_line_net_after_discount_excluding_tax',
                            'line_unit_price_minor' => (int) $orderItem->unit_price,
                            'line_quantity' => (int) $orderItem->quantity,
                            'line_discount_minor' => (int) ($orderItem->discount_amount ?? 0),
                            'line_tax_minor_excluded' => (int) ($orderItem->tax_amount ?? 0),
                            'registration_net_minor' => $netAmount,
                        ],
                    ],
                );

                if ($conversion !== null) {
                    $recorded[] = $registrationId;
                }
            }
        }

        return array_values(array_unique($recorded));
    }
}
