<?php

declare(strict_types=1);

namespace App\Listeners\Events;

use AIArmada\CommerceSupport\Events\PaymentRefunded;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Models\OrderRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completes an event refund after the provider confirms it asynchronously.
 *
 * The provider event is deliberately translated into the shared Orders
 * transition. That keeps event capacity, passes, and order accounting aligned
 * for CHIP today and other providers later.
 */
final class CompletePendingEventRefundOnPaymentRefunded
{
    public function __construct(
        private readonly OrderServiceInterface $orders,
    ) {}

    public function handle(PaymentRefunded $event): void
    {
        if ($event->amount <= 0 || trim($event->provider) === '') {
            return;
        }

        $provider = mb_strtolower(mb_trim($event->provider));
        $paymentId = $event->paymentId !== null ? mb_trim($event->paymentId) : null;

        $baseQuery = $this->refundQuery($event);

        // A provider-side refund id is the only safe match when several
        // admissions share one purchase and amount. The registration metadata
        // remains the canonical correlation when a provider does not expose a
        // separate refund-operation id.
        if ($paymentId !== null && $paymentId !== '') {
            $refunds = $this->refundQuery($event, includeCompleted: true)
                ->where('metadata->provider_refund_id', $paymentId)
                ->where('metadata->provider', $provider)
                ->get();

            if ($refunds->count() === 1) {
                $refund = $refunds->first();

                if ($refund instanceof OrderRefund && $refund->isPending()) {
                    $this->complete($refund, $event);
                }

                return;
            }

            if ($refunds->count() > 1) {
                $this->warnAmbiguous($event, $refunds->count());

                return;
            }
        }

        $registrationId = data_get($event->metadata, 'event_registration_id');

        if (is_scalar($registrationId) && trim((string) $registrationId) !== '') {
            $refunds = $this->refundQuery($event, includeCompleted: true)
                ->where('metadata->event_registration_id', (string) $registrationId)
                ->get();

            if ($refunds->count() === 1) {
                $refund = $refunds->first();

                if ($refund instanceof OrderRefund && $refund->isPending()) {
                    $this->recordProviderCorrelation($refund, $paymentId, $event->relatedPaymentId);
                    $this->complete($refund, $event);
                }

                return;
            }

            if ($refunds->count() > 1) {
                $this->warnAmbiguous($event, $refunds->count());

                return;
            }
        }

        if ($event->relatedPaymentId === null || trim($event->relatedPaymentId) === '') {
            return;
        }

        $refunds = $baseQuery
            ->where('metadata->payment_id', $event->relatedPaymentId)
            ->where('metadata->provider', $provider)
            ->get();

        if ($refunds->count() !== 1) {
            if ($refunds->isNotEmpty()) {
                $this->warnAmbiguous($event, $refunds->count());
            }

            return;
        }

        $refund = $refunds->first();

        if (! $refund instanceof OrderRefund) {
            return;
        }

        if (! $this->recordProviderCorrelation($refund, $paymentId, $event->relatedPaymentId)) {
            return;
        }

        $this->complete($refund, $event);
    }

    /**
     * @return Builder<OrderRefund>
     */
    private function refundQuery(PaymentRefunded $event, bool $includeCompleted = false): Builder
    {
        $statuses = $includeCompleted
            ? [RefundStatus::Pending, RefundStatus::Completed]
            : [RefundStatus::Pending];

        return OrderRefund::query()
            ->with(['order', 'payment'])
            ->whereIn('status', $statuses)
            ->where('amount', $event->amount)
            ->where('currency', mb_strtoupper($event->currency))
            ->whereNotNull('metadata->event_registration_id');
    }

    private function recordProviderCorrelation(
        OrderRefund $refund,
        ?string $paymentId,
        ?string $relatedPaymentId,
    ): bool {
        if ($paymentId === null || $paymentId === '' || $paymentId === $relatedPaymentId) {
            return true;
        }

        $metadata = is_array($refund->metadata) ? $refund->metadata : [];
        $existingId = data_get($metadata, 'provider_refund_id');

        if (is_scalar($existingId) && trim((string) $existingId) !== '') {
            return trim((string) $existingId) === $paymentId;
        }

        $metadata['provider_refund_id'] = $paymentId;
        $refund->forceFill(['metadata' => $metadata])->save();

        return true;
    }

    private function complete(mixed $refund, PaymentRefunded $event): void
    {
        if (! $refund instanceof OrderRefund) {
            return;
        }

        try {
            OwnerContext::withOwner($refund->order->owner ?? null, function () use ($refund, $event): void {
                $this->orders->completePendingRefund(
                    $refund,
                    $event->paymentId ?? $event->reference,
                );
            });
        } catch (Throwable $exception) {
            Log::error('Remote payment refund was confirmed but could not be recorded locally', [
                'provider' => $event->provider,
                'payment_id' => $event->paymentId,
                'related_payment_id' => $event->relatedPaymentId,
                'refund_id' => $refund->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function warnAmbiguous(PaymentRefunded $event, int $count): void
    {
        Log::warning('Remote payment refund could not be matched unambiguously to an event refund', [
            'provider' => $event->provider,
            'payment_id' => $event->paymentId,
            'related_payment_id' => $event->relatedPaymentId,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'pending_refund_count' => $count,
        ]);
    }
}
