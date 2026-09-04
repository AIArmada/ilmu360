<?php

use AIArmada\Checkout\Contracts\PaymentGatewayResolverInterface;
use AIArmada\Checkout\Contracts\PaymentProcessorInterface;
use AIArmada\Checkout\Data\PaymentResult;
use AIArmada\Checkout\Enums\PaymentStatus as CheckoutPaymentStatus;
use AIArmada\CommerceSupport\Events\PaymentRefunded as CommercePaymentRefunded;
use AIArmada\Orders\Enums\PaymentStatus as OrderPaymentStatus;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\States\Processing;
use App\Actions\Events\ProcessEventRefundAction;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('orders.owner.enabled', false);
    config()->set('events.features.commerce.refunds_enabled_by_default', false);
});

it('refunds one purchaser admission through the pending provider workflow', function (): void {
    $buyer = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => true]],
    ]);
    $order = Order::create([
        'order_number' => 'ORD-EVENT-REFUND-'.str()->upper(str()->random(8)),
        'status' => Processing::class,
        'subtotal' => 5000,
        'grand_total' => 5000,
        'currency' => 'MYR',
        'paid_at' => now(),
    ]);
    $payment = OrderPayment::create([
        'order_id' => $order->getKey(),
        'gateway' => 'chip',
        'transaction_id' => 'chip-payment-1',
        'amount' => 5000,
        'currency' => 'MYR',
        'status' => OrderPaymentStatus::Completed,
        'paid_at' => now(),
        'metadata' => [
            'payment_id' => 'chip-payment-1',
            'provider' => 'chip',
        ],
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->forRegistrant($buyer)
        ->create([
            'status' => 'confirmed',
            'total_amount' => 5000,
            'currency' => 'MYR',
            'external_order_id' => $order->getKey(),
            'external_order_type' => Order::class,
        ]);

    expect($payment->exists)->toBeTrue();

    $processor = Mockery::mock(PaymentProcessorInterface::class);
    $processor->shouldReceive('refund')
        ->once()
        ->with('chip-payment-1', 5000, 'Refund requested by purchaser.')
        ->andReturn(new PaymentResult(
            status: CheckoutPaymentStatus::Refunded,
            paymentId: 'chip-payment-1',
            transactionId: 'chip-refund-1',
            amount: 5000,
            currency: 'MYR',
            provider: 'chip',
        ));

    $resolver = Mockery::mock(PaymentGatewayResolverInterface::class);
    $resolver->shouldReceive('hasGateway')->with('chip')->andReturnTrue();
    $resolver->shouldReceive('resolve')->with('chip')->andReturn($processor);
    app()->instance(PaymentGatewayResolverInterface::class, $resolver);

    $refund = app(ProcessEventRefundAction::class)->handle($event, $registration, $buyer);

    expect($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->transaction_id)->toBe('chip-refund-1')
        ->and($refund->amount)->toBe(5000)
        ->and($refund->metadata['event_registration_id'])->toBe($registration->getKey())
        ->and($registration->fresh()->statusValue())->toBe('refunded')
        ->and($registration->fresh()->refunded_at)->not->toBeNull();
});

it('keeps the admission reserved while the provider refund is processing', function (): void {
    $buyer = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => true]],
    ]);
    $order = Order::create([
        'order_number' => 'ORD-EVENT-REFUND-'.str()->upper(str()->random(8)),
        'status' => Processing::class,
        'subtotal' => 5000,
        'grand_total' => 5000,
        'currency' => 'MYR',
        'paid_at' => now(),
    ]);
    OrderPayment::create([
        'order_id' => $order->getKey(),
        'gateway' => 'chip',
        'transaction_id' => 'chip-payment-pending',
        'amount' => 5000,
        'currency' => 'MYR',
        'status' => OrderPaymentStatus::Completed,
        'paid_at' => now(),
        'metadata' => ['payment_id' => 'chip-payment-pending', 'provider' => 'chip'],
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->forRegistrant($buyer)
        ->create([
            'status' => 'confirmed',
            'total_amount' => 5000,
            'currency' => 'MYR',
            'external_order_id' => $order->getKey(),
            'external_order_type' => Order::class,
        ]);

    $processor = Mockery::mock(PaymentProcessorInterface::class);
    $processor->shouldReceive('refund')
        ->once()
        ->andReturn(PaymentResult::processing('chip-payment-pending', 'chip'));
    $resolver = Mockery::mock(PaymentGatewayResolverInterface::class);
    $resolver->shouldReceive('hasGateway')->with('chip')->andReturnTrue();
    $resolver->shouldReceive('resolve')->with('chip')->andReturn($processor);
    app()->instance(PaymentGatewayResolverInterface::class, $resolver);

    $refund = app(ProcessEventRefundAction::class)->handle($event, $registration, $buyer);

    expect($refund->status)->toBe(RefundStatus::Pending)
        ->and($registration->fresh()->statusValue())->toBe('refund_pending')
        ->and($registration->fresh()->refund_pending_at)->not->toBeNull();
});

it('does not allow a participant who is not the purchaser to self refund', function (): void {
    $buyer = User::factory()->create();
    $participant = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => true]],
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->forRegistrant($buyer)
        ->create([
            'status' => 'confirmed',
            'total_amount' => 5000,
        ]);

    expect(Gate::forUser($participant)->allows('requestRefund', $registration))->toBeFalse();

    app(ProcessEventRefundAction::class)->handle(
        event: $event,
        registration: $registration,
        actor: $participant,
    );
})->throws(AuthorizationException::class);

it('completes a pending event refund from a provider-neutral refund event', function (): void {
    $buyer = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => true]],
    ]);
    $order = Order::create([
        'order_number' => 'ORD-EVENT-REFUND-'.str()->upper(str()->random(8)),
        'status' => Processing::class,
        'subtotal' => 5000,
        'grand_total' => 5000,
        'currency' => 'MYR',
        'paid_at' => now(),
    ]);
    $payment = OrderPayment::create([
        'order_id' => $order->getKey(),
        'gateway' => 'cashier',
        'transaction_id' => 'chip-payment-webhook',
        'amount' => 5000,
        'currency' => 'MYR',
        'status' => OrderPaymentStatus::Completed,
        'paid_at' => now(),
        'metadata' => ['payment_id' => 'chip-payment-webhook', 'provider' => 'chip'],
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->forRegistrant($buyer)
        ->create([
            'status' => 'refund_pending',
            'refund_pending_at' => now(),
            'total_amount' => 5000,
            'currency' => 'MYR',
            'external_order_id' => $order->getKey(),
            'external_order_type' => Order::class,
        ]);
    $refund = $order->refunds()->create([
        'payment_id' => $payment->getKey(),
        'gateway' => 'cashier',
        'transaction_id' => 'event-refund-'.$registration->getKey(),
        'amount' => 5000,
        'currency' => 'MYR',
        'status' => RefundStatus::Pending,
        'reason' => 'Refund requested by purchaser.',
        'metadata' => [
            'event_registration_scope' => 'selected',
            'event_registration_ids' => [$registration->getKey()],
            'event_registration_id' => $registration->getKey(),
            'payment_id' => 'chip-payment-webhook',
            'provider' => 'chip',
            'refund_allocations' => [$registration->getKey() => 5000],
        ],
    ]);

    event(new CommercePaymentRefunded(
        provider: 'chip',
        paymentId: 'chip-refund-webhook',
        relatedPaymentId: 'chip-payment-webhook',
        amount: 5000,
        currency: 'MYR',
    ));

    expect($refund->fresh()->status)->toBe(RefundStatus::Completed)
        ->and($registration->fresh()->statusValue())->toBe('refunded')
        ->and($registration->fresh()->refunded_at)->not->toBeNull();
});

it('uses the provider refund id when several admissions share the same payment', function (): void {
    $buyer = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => true]],
    ]);
    $order = Order::create([
        'order_number' => 'ORD-EVENT-REFUND-'.str()->upper(str()->random(8)),
        'status' => Processing::class,
        'subtotal' => 10000,
        'grand_total' => 10000,
        'currency' => 'MYR',
        'paid_at' => now(),
    ]);
    $payment = OrderPayment::create([
        'order_id' => $order->getKey(),
        'gateway' => 'cashier',
        'transaction_id' => 'chip-payment-shared',
        'amount' => 10000,
        'currency' => 'MYR',
        'status' => OrderPaymentStatus::Completed,
        'paid_at' => now(),
        'metadata' => ['payment_id' => 'chip-payment-shared', 'provider' => 'chip'],
    ]);
    $registrations = collect([
        Registration::factory()->for($event)->forRegistrant($buyer)->create([
            'status' => 'refund_pending',
            'refund_pending_at' => now(),
            'total_amount' => 5000,
            'currency' => 'MYR',
            'external_order_id' => $order->getKey(),
            'external_order_type' => Order::class,
        ]),
        Registration::factory()->for($event)->forRegistrant($buyer)->create([
            'status' => 'refund_pending',
            'refund_pending_at' => now(),
            'total_amount' => 5000,
            'currency' => 'MYR',
            'external_order_id' => $order->getKey(),
            'external_order_type' => Order::class,
        ]),
    ]);

    $refunds = $registrations->values()->map(function (Registration $registration, int $index) use ($order, $payment): mixed {
        return $order->refunds()->create([
            'payment_id' => $payment->getKey(),
            'gateway' => 'cashier',
            'transaction_id' => 'event-refund-'.$registration->getKey(),
            'amount' => 5000,
            'currency' => 'MYR',
            'status' => RefundStatus::Pending,
            'reason' => 'Refund requested by purchaser.',
            'metadata' => [
                'event_registration_scope' => 'selected',
                'event_registration_ids' => [$registration->getKey()],
                'event_registration_id' => $registration->getKey(),
                'payment_id' => 'chip-payment-shared',
                'provider' => 'chip',
                'provider_refund_id' => 'chip-refund-'.$index,
            ],
        ]);
    });

    event(new CommercePaymentRefunded(
        provider: 'chip',
        paymentId: 'chip-refund-1',
        relatedPaymentId: 'chip-payment-shared',
        amount: 5000,
        currency: 'MYR',
    ));
    event(new CommercePaymentRefunded(
        provider: 'chip',
        paymentId: 'chip-refund-1',
        relatedPaymentId: 'chip-payment-shared',
        amount: 5000,
        currency: 'MYR',
    ));

    expect($refunds[0]->fresh()->status)->toBe(RefundStatus::Pending)
        ->and($refunds[1]->fresh()->status)->toBe(RefundStatus::Completed)
        ->and($registrations[0]->fresh()->statusValue())->toBe('refund_pending')
        ->and($registrations[1]->fresh()->statusValue())->toBe('refunded');
});

it('keeps refunds hidden when the event policy is off', function (): void {
    $buyer = User::factory()->create();
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(7),
        'metadata' => ['registration' => ['refunds_enabled' => false]],
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->forRegistrant($buyer)
        ->create([
            'status' => 'confirmed',
            'total_amount' => 5000,
        ]);

    expect(Gate::forUser($buyer)->allows('requestRefund', $registration))->toBeFalse();
});
