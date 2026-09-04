<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Checkout\Contracts\PaymentGatewayResolverInterface;
use AIArmada\Checkout\Contracts\PaymentProcessorInterface;
use AIArmada\Checkout\Contracts\ProviderAwarePaymentProcessorInterface;
use AIArmada\Checkout\Data\PaymentResult;
use AIArmada\Checkout\Enums\PaymentStatus as CheckoutPaymentStatus;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Contracts\RegistrationServiceInterface;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Enums\PaymentStatus as OrderPaymentStatus;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\EventCommercePolicy;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Processes one admission refund through the shared provider-neutral stack.
 *
 * The order/refund record is created before calling the provider. This keeps
 * a pending financial operation auditable and prevents capacity from being
 * released until a provider-confirmed completion is recorded.
 */
final class ProcessEventRefundAction
{
    use AsAction;

    public function __construct(
        private readonly EventCommercePolicy $policy,
        private readonly OrderServiceInterface $orders,
        private readonly PaymentGatewayResolverInterface $paymentGateways,
        private readonly RegistrationServiceInterface $registrations,
    ) {}

    public function handle(
        Event $event,
        Registration $registration,
        User $actor,
        bool $organizerOverride = false,
        ?string $reason = null,
    ): OrderRefund {
        $registration = $registration->fresh([
            'event',
            'occurrence',
            'session',
            'participants',
            'attendances',
        ]) ?? $registration;

        $this->authorize($event, $registration, $actor, $organizerOverride);
        $this->assertRefundAllowed($event, $registration, $actor, $organizerOverride);

        $order = $this->resolveOrder($registration);

        if (! $order instanceof Order) {
            throw new InvalidArgumentException('This admission is not connected to a refundable order.');
        }

        if (! $order->isPaid()) {
            throw new InvalidArgumentException('This order has no completed payment to refund.');
        }

        $existing = $this->existingRefund($order, $registration);

        if ($existing instanceof OrderRefund) {
            if ($existing->isFailed()) {
                throw new RuntimeException('The refund request has already failed and requires review.');
            }

            if ($existing->isCompleted()) {
                return $existing;
            }

            $this->markRegistrationRefundPending($registration);

            // A provider call may have completed even when the application
            // did not receive its response. Never submit a claimed refund a
            // second time because the provider-neutral contract cannot prove
            // whether an exception happened before or after money moved.
            if ($existing->hasProviderSubmissionStarted()) {
                return $existing->fresh() ?? $existing;
            }
        }

        $amount = $existing instanceof OrderRefund
            ? (int) $existing->amount
            : (int) ($registration->total_amount ?? 0);
        $payment = $this->completedPayment($order);
        $this->assertOnlinePayment($payment);
        $paymentId = $this->paymentId($payment);
        $provider = $this->paymentProvider($payment);
        $processor = $this->resolveProcessor($payment, $provider);
        $mode = $organizerOverride ? 'organizer_approved' : 'self_service';
        $refundReason = $existing instanceof OrderRefund
            ? $existing->reason
            : $this->normalizeReason($reason, $mode);

        $refund = $existing;

        if (! $refund instanceof OrderRefund) {
            $metadata = $this->refundMetadata($event, $registration, $actor, $mode, $amount, $paymentId, $provider);

            $refund = OwnerContext::withOwner(null, fn (): OrderRefund => $this->orders->createPendingRefund(
                order: $order,
                amount: $amount,
                transactionId: 'event-refund-'.(string) $registration->getKey(),
                reason: $refundReason,
                metadata: $metadata,
            ));
        }

        if ($refund->isFailed()) {
            throw new RuntimeException('The refund request has already failed and requires review.');
        }

        if ($refund->isCompleted()) {
            return $refund;
        }

        if (! $refund->wasRecentlyCreated) {
            $amount = (int) $refund->amount;
            $refundReason = $refund->reason;
        }

        $this->markRegistrationRefundPending($registration);

        $claimed = OwnerContext::withOwner(null, fn (): bool => $this->orders->claimPendingRefundSubmission($refund));

        if (! $claimed) {
            return $refund->fresh() ?? $refund;
        }

        try {
            $result = $this->refundPayment($processor, $provider, $paymentId, $amount, $refundReason);
        } catch (Throwable $exception) {
            // The provider-neutral processor contract cannot distinguish a
            // rejected request from a transport failure after submission. A
            // pending record prevents a second money-moving request; the
            // provider webhook or reconciliation workflow must resolve it.
            throw $exception;
        }

        $this->recordProviderCorrelation($refund, $result);

        if ($this->isProviderConfirmed($result)) {
            // A local completion failure must leave the refund pending. The
            // provider may already have completed the money movement, so it
            // would be unsafe to turn that local failure into a provider
            // failure and restore the admission automatically.
            OwnerContext::withOwner(null, function () use ($refund, $result): void {
                $this->orders->completePendingRefund(
                    $refund,
                    $result->transactionId ?? $result->paymentId,
                );
            });

            return $refund->fresh() ?? $refund;
        }

        if ($this->isProviderFailed($result)) {
            $this->failRefund($refund, $result->message ?? 'The payment provider rejected the refund.');

            throw new RuntimeException('The payment provider could not process the refund.');
        }

        return $refund->fresh() ?? $refund;
    }

    private function authorize(
        Event $event,
        Registration $registration,
        User $actor,
        bool $organizerOverride,
    ): void {
        if ($organizerOverride) {
            Gate::forUser($actor)->authorize('manageAdmissions', $event);

            return;
        }

        Gate::forUser($actor)->authorize('requestRefund', $registration);
    }

    private function assertRefundAllowed(
        Event $event,
        Registration $registration,
        User $actor,
        bool $organizerOverride,
    ): void {
        if ($organizerOverride) {
            if (! $this->policy->canOrganizerRefund($event, $registration)) {
                throw new InvalidArgumentException('This admission is not eligible for an organizer refund.');
            }

            return;
        }

        $reason = $this->policy->selfServiceRefundReason($actor, $event, $registration);

        if ($reason !== null) {
            throw new InvalidArgumentException(match ($reason) {
                'refunds_disabled' => 'Refunds are not enabled for this event.',
                'purchaser_only' => 'Only the purchaser can request a refund.',
                'refund_window_closed' => 'The self-service refund window has closed.',
                'admission_already_used' => 'A used admission cannot be refunded online.',
                'free_admission' => 'Free admissions do not have a payment to refund.',
                default => 'This admission is not eligible for a self-service refund.',
            });
        }
    }

    private function resolveOrder(Registration $registration): ?Order
    {
        if ($registration->external_order_id === null
            || $registration->external_order_type !== Order::class) {
            return null;
        }

        return OwnerContext::withOwner(null, fn (): ?Order => Order::query()
            ->with(['payments', 'refunds'])
            ->whereKey($registration->external_order_id)
            ->first());
    }

    private function existingRefund(Order $order, Registration $registration): ?OrderRefund
    {
        return $order->refunds->first(function (OrderRefund $refund) use ($registration): bool {
            $registrationId = data_get($refund->metadata ?? [], 'event_registration_id');

            return (string) $registrationId === (string) $registration->getKey();
        });
    }

    private function markRegistrationRefundPending(Registration $registration): void
    {
        OwnerContext::withOwner(null, function () use ($registration): void {
            $this->registrations->markRefundPending(
                $registration,
                'Refund is awaiting payment provider confirmation.',
            );
        });
    }

    private function completedPayment(Order $order): OrderPayment
    {
        $payment = $order->payments->first(
            fn (OrderPayment $candidate): bool => $candidate->status === OrderPaymentStatus::Completed,
        );

        if (! $payment instanceof OrderPayment) {
            throw new InvalidArgumentException('This order has no completed payment to refund.');
        }

        return $payment;
    }

    private function assertOnlinePayment(OrderPayment $payment): void
    {
        if (str_starts_with(mb_strtolower(trim((string) $payment->gateway)), 'offline_')) {
            throw new InvalidArgumentException('Offline admissions must be refunded outside the online payment workflow.');
        }
    }

    private function paymentId(OrderPayment $payment): string
    {
        $paymentId = data_get($payment->metadata ?? [], 'payment_id');

        if (! is_scalar($paymentId) || trim((string) $paymentId) === '') {
            throw new InvalidArgumentException('The completed payment has no provider reference for refunding.');
        }

        return trim((string) $paymentId);
    }

    private function resolveProcessor(OrderPayment $payment, ?string $provider): PaymentProcessorInterface
    {
        $identifier = trim((string) $payment->gateway);

        if ($identifier !== '' && $this->paymentGateways->hasGateway($identifier)) {
            return $this->paymentGateways->resolve($identifier);
        }

        throw new RuntimeException("The payment processor [{$identifier}] is no longer available.");
    }

    private function paymentProvider(OrderPayment $payment): ?string
    {
        $provider = data_get($payment->metadata ?? [], 'provider');

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : null;
    }

    private function refundPayment(
        PaymentProcessorInterface $processor,
        ?string $provider,
        string $paymentId,
        int $amount,
        string $reason,
    ): PaymentResult {
        if ($provider !== null && $processor instanceof ProviderAwarePaymentProcessorInterface) {
            return $processor->refundForProvider($provider, $paymentId, $amount, $reason);
        }

        return $processor->refund($paymentId, $amount, $reason);
    }

    private function isProviderConfirmed(PaymentResult $result): bool
    {
        return in_array($result->status, [
            CheckoutPaymentStatus::Completed,
            CheckoutPaymentStatus::Refunded,
            CheckoutPaymentStatus::PartiallyRefunded,
        ], true);
    }

    private function isProviderFailed(PaymentResult $result): bool
    {
        return in_array($result->status, [
            CheckoutPaymentStatus::Failed,
            CheckoutPaymentStatus::Cancelled,
        ], true);
    }

    private function failRefund(OrderRefund $refund, string $reason): void
    {
        OwnerContext::withOwner(null, fn (): OrderRefund => $this->orders->failPendingRefund(
            $refund,
            mb_substr(trim($reason) !== '' ? trim($reason) : 'Refund failed.', 0, 500),
        ));
    }

    private function recordProviderCorrelation(OrderRefund $refund, PaymentResult $result): void
    {
        $providerRefundId = $result->transactionId;

        if ($providerRefundId === null || trim($providerRefundId) === '') {
            return;
        }

        $metadata = is_array($refund->metadata) ? $refund->metadata : [];
        $originalPaymentId = data_get($metadata, 'payment_id');

        if ((string) $providerRefundId === (string) $originalPaymentId) {
            return;
        }

        $metadata['provider_refund_id'] = trim($providerRefundId);

        OwnerContext::withOwner(null, function () use ($metadata, $refund): void {
            $refund->forceFill(['metadata' => $metadata])->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function refundMetadata(
        Event $event,
        Registration $registration,
        User $actor,
        string $mode,
        int $amount,
        string $paymentId,
        ?string $provider,
    ): array {
        return [
            'event_registration_scope' => 'selected',
            'event_registration_ids' => [(string) $registration->getKey()],
            'event_registration_id' => (string) $registration->getKey(),
            'refund_allocations' => [(string) $registration->getKey() => $amount],
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event_refund' => [
                'event_id' => (string) $event->getKey(),
                'registration_id' => (string) $registration->getKey(),
                'mode' => $mode,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => (string) $actor->getKey(),
            ],
        ];
    }

    private function normalizeReason(?string $reason, string $mode): string
    {
        $reason = trim((string) $reason);

        if ($mode === 'organizer_approved' && $reason === '') {
            throw new InvalidArgumentException('An organizer refund requires a reason.');
        }

        return mb_substr($reason !== '' ? $reason : 'Refund requested by purchaser.', 0, 500);
    }
}
