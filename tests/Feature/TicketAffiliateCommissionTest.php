<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Payouts\CreatePayout;
use AIArmada\Affiliates\Actions\Payouts\UpdatePayoutStatus;
use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateProgram;
use AIArmada\Affiliates\Models\AffiliateProgramMembership;
use AIArmada\Affiliates\States\Active as AffiliateActive;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\ReversedConversion;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Events\Contracts\RegistrationServiceInterface;
use AIArmada\Events\Events\EventRegistrationCancelled;
use AIArmada\Events\Events\EventRegistrationRefunded;
use AIArmada\Events\Events\EventRegistrationRejected;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\States\Processing;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\RecordTicketAffiliateCommissionsAction;
use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use App\Checkout\RecordTicketAffiliateCommissionsStep;
use App\Enums\ShareEffectStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\ShareEffectReceipt;
use App\Models\User;
use App\States\EventStatus\Approved as EventApproved;
use App\States\EventStatus\Transitions\CancelEvent;
use Illuminate\Support\Str;

/**
 * @return array{
 *     session: CheckoutSession,
 *     order: Order,
 *     order_item: OrderItem,
 *     registrations: list<Registration>,
 *     affiliate: Affiliate,
 *     cart_id: string
 * }
 */
function seedTicketAffiliateCommissionCheckout(
    bool $selfPurchase = false,
    int $grandTotal = 9901,
    int $unitPrice = 5000,
    int $discountAmount = 999,
    int $taxAmount = 900,
    ?string $affiliatePurpose = null,
): array {
    $affiliateOwner = User::factory()->create();
    $buyer = $selfPurchase ? $affiliateOwner : User::factory()->create();
    $affiliate = new Affiliate;
    $affiliate->forceFill([
        'code' => 'TICKET-'.mb_strtoupper(Str::random(8)),
        'name' => 'Ticket Commission Affiliate',
        'status' => AffiliateActive::class,
        'commission_type' => CommissionType::Percentage,
        'commission_rate' => 1000,
        'currency' => 'MYR',
        'owner_type' => $affiliateOwner->getMorphClass(),
        'owner_id' => $affiliateOwner->getKey(),
        'purpose' => $affiliatePurpose,
    ])->save();

    $program = AffiliateProgram::query()->create([
        'name' => 'Ticket Commission Program',
        'slug' => 'ticket-commission-'.mb_strtolower(Str::random(8)),
        'status' => 'active',
        'requires_approval' => false,
        'commission_type' => 'percentage',
        'default_commission_rate_basis_points' => 1000,
        'currency' => 'MYR',
    ]);

    AffiliateProgramMembership::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'program_id' => $program->getKey(),
        'status' => 'approved',
        'applied_at' => now(),
        'approved_at' => now(),
    ]);

    $cart = app('cart')->getCurrentCart();
    $cart->clear();
    $cart->add('paid-event-ticket', 'Paid Event Ticket', $unitPrice, 2);
    $cartId = $cart->getId();

    if (! is_string($cartId) || $cartId === '') {
        throw new RuntimeException('A persisted cart id is required by the ticket commission test fixture.');
    }

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'affiliate_program_id' => $program->getKey(),
        'cart_identifier' => $cart->getIdentifier(),
        'cart_instance' => $cart->instance(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $session = CheckoutSession::query()->forceCreate([
        'cart_id' => $cartId,
        'cart_snapshot' => ['items' => []],
        'payment_data' => [
            'type' => 'card',
            'affiliate_commission' => [
                'strategy' => 'registration_lines',
                'attribution_id' => $attribution->getKey(),
                'affiliate_id' => $affiliate->getKey(),
                'affiliate_program_id' => $program->getKey(),
                'owner_type' => null,
                'owner_id' => null,
                'commercial_purpose' => $affiliatePurpose === null || $affiliatePurpose === 'general',
            ],
            'checkout_actor' => [
                'type' => $buyer->getMorphClass(),
                'id' => (string) $buyer->getKey(),
            ],
        ],
        'subtotal' => $unitPrice * 2,
        'grand_total' => $grandTotal,
        'currency' => 'MYR',
    ]);

    $order = Order::query()->create([
        'status' => Processing::class,
        'subtotal' => $unitPrice * 2,
        'discount_total' => $discountAmount,
        'tax_total' => $taxAmount,
        'grand_total' => $grandTotal,
        'currency' => 'MYR',
        'metadata' => [
            'checkout_session_id' => $session->getKey(),
            'cart_id' => $cartId,
            'payment_data' => $session->payment_data,
        ],
        'paid_at' => now(),
    ]);
    $session->forceFill(['order_id' => $order->getKey()])->save();

    $orderItem = new OrderItem;
    $orderItem->forceFill([
        'order_id' => $order->getKey(),
        'name' => 'Paid Event Ticket',
        'quantity' => 2,
        'unit_price' => $unitPrice,
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'total' => ($unitPrice * 2) - $discountAmount + $taxAmount,
        'currency' => 'MYR',
    ])->save();

    $firstRegistration = Registration::factory()->create([
        'external_order_id' => $order->getKey(),
        'external_order_type' => Order::class,
        'status' => 'confirmed',
        'total_amount' => $unitPrice,
        'currency' => 'MYR',
        'registration_no' => 'TICKET-COMMISSION-1-'.Str::upper(Str::random(6)),
    ]);
    Event::query()
        ->whereKey($firstRegistration->event_id)
        ->update(['pricing_mode' => $unitPrice > 0 ? 'paid' : 'free']);
    $ticketType = TicketType::query()->create([
        'ticketable_type' => Event::class,
        'ticketable_id' => $firstRegistration->event_id,
        'name' => 'Paid event ticket',
        'code' => 'PAID-'.mb_strtoupper(Str::random(8)),
        'access_type' => 'general',
        'price' => $unitPrice,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $registrations = [$firstRegistration];

    foreach ([1, 2] as $index) {
        if ($index === 1) {
            $registration = $firstRegistration;
        } else {
            $registration = Registration::factory()->create([
                'event_id' => $firstRegistration->event_id,
                'external_order_id' => $order->getKey(),
                'external_order_type' => Order::class,
                'status' => 'confirmed',
                'total_amount' => $unitPrice,
                'currency' => 'MYR',
                'registration_no' => 'TICKET-COMMISSION-'.$index.'-'.Str::upper(Str::random(6)),
            ]);
            $registrations[] = $registration;
        }

        EventRegistrationItem::query()->create([
            'event_registration_id' => $registration->getKey(),
            'event_id' => $registration->event_id,
            'ticket_type_id' => $ticketType->getKey(),
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice,
            'currency' => 'MYR',
            'external_order_item_id' => $orderItem->getKey(),
            'external_order_item_type' => OrderItem::class,
            'status' => 'confirmed',
        ]);

    }

    return [
        'session' => $session->fresh(),
        'order' => $order,
        'order_item' => $orderItem,
        'registrations' => $registrations,
        'affiliate' => $affiliate,
        'cart_id' => $cartId,
    ];
}

it('records one pending conversion per paid registration from the frozen commercial attribution', function (): void {
    $fixture = seedTicketAffiliateCommissionCheckout();
    $session = $fixture['session'];
    $registrationIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $fixture['registrations'],
    );
    $session->setStepData('create_event_registrations', ['registration_ids' => $registrationIds]);

    $step = app(RecordTicketAffiliateCommissionsStep::class);
    $result = $step->handle($session);
    $resultOnRetry = $step->handle($session->fresh());
    $conversions = AffiliateConversion::query()
        ->where('affiliate_id', $fixture['affiliate']->getKey())
        ->get();

    expect($result->isSuccessful())->toBeTrue()
        ->and($resultOnRetry->isSuccessful())->toBeTrue()
        ->and(AffiliateConversion::query()->count())->toBe(2)
        ->and($conversions)->toHaveCount(2);

    foreach ($conversions as $conversion) {
        expect($conversion->status)->toBeInstanceOf('AIArmada\\Affiliates\\States\\PendingConversion')
            ->and($conversion->subtotal_minor)->toBeIn([4500, 4501])
            ->and($conversion->value_minor)->toBe($conversion->subtotal_minor)
            ->and($conversion->commission_minor)->toBe((int) round($conversion->subtotal_minor * 0.1))
            ->and(data_get($conversion->metadata, 'commission_basis'))->toBe('paid_line_net_after_discount_excluding_tax')
            ->and(data_get($conversion->metadata, 'line_tax_minor_excluded'))->toBe(900);
    }

    $ids = array_map(static fn (Registration $registration): string => (string) $registration->getKey(), $fixture['registrations']);
    sort($ids, SORT_STRING);
    $byRegistration = $conversions->keyBy(static fn (AffiliateConversion $conversion): string => (string) data_get($conversion->metadata, 'registration_id'));

    expect(data_get($byRegistration->get($ids[0])?->metadata, 'registration_net_minor'))->toBe(4501)
        ->and(data_get($byRegistration->get($ids[1])?->metadata, 'registration_net_minor'))->toBe(4500);
});

it('blocks commission for affiliate-owner self purchases and free registrations', function (): void {
    $selfPurchase = seedTicketAffiliateCommissionCheckout(selfPurchase: true);
    $selfIds = array_map(static fn (Registration $registration): string => (string) $registration->getKey(), $selfPurchase['registrations']);

    expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($selfPurchase['session'], $selfIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $free = seedTicketAffiliateCommissionCheckout(grandTotal: 0, unitPrice: 0, discountAmount: 0, taxAmount: 0);
    $freeIds = array_map(static fn (Registration $registration): string => (string) $registration->getKey(), $free['registrations']);

    expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($free['session'], $freeIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('does not pay commercial commission for a Dawah-purpose affiliate', function (): void {
    $fixture = seedTicketAffiliateCommissionCheckout(affiliatePurpose: 'share_member');
    $registrationIds = array_map(static fn (Registration $registration): string => (string) $registration->getKey(), $fixture['registrations']);

    expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($fixture['session'], $registrationIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('requires frozen commercial purpose and active program membership', function (): void {
    $dawah = seedTicketAffiliateCommissionCheckout(affiliatePurpose: 'share_member');
    $dawah['affiliate']->forceFill(['purpose' => null])->save();
    $dawahRegistrationIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $dawah['registrations'],
    );

    expect(app(RecordTicketAffiliateCommissionsAction::class)
        ->handle($dawah['session'], $dawahRegistrationIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $inactiveProgram = seedTicketAffiliateCommissionCheckout();
    AffiliateProgram::query()->whereKey($inactiveProgram['session']->payment_data['affiliate_commission']['affiliate_program_id'])
        ->update(['status' => 'paused']);
    $inactiveProgramIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $inactiveProgram['registrations'],
    );

    expect(app(RecordTicketAffiliateCommissionsAction::class)
        ->handle($inactiveProgram['session'], $inactiveProgramIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $inactiveMembership = seedTicketAffiliateCommissionCheckout();
    AffiliateProgramMembership::query()
        ->where('affiliate_id', $inactiveMembership['affiliate']->getKey())
        ->update(['status' => 'pending']);
    $inactiveMembershipIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $inactiveMembership['registrations'],
    );

    expect(app(RecordTicketAffiliateCommissionsAction::class)
        ->handle($inactiveMembership['session'], $inactiveMembershipIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('blocks commission when a commercial affiliate is paused or deactivated', function (): void {
    foreach (['paused_at' => now(), 'deactivated_at' => now()] as $field => $value) {
        $fixture = seedTicketAffiliateCommissionCheckout();
        $fixture['affiliate']->forceFill([$field => $value])->save();
        $registrationIds = array_map(
            static fn (Registration $registration): string => (string) $registration->getKey(),
            $fixture['registrations'],
        );

        expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($fixture['session'], $registrationIds))->toBe([])
            ->and(AffiliateConversion::query()->count())->toBe(0);
    }
});

it('fails closed when the paid order has no frozen checkout actor', function (): void {
    $fixture = seedTicketAffiliateCommissionCheckout();
    $orderMetadata = $fixture['order']->metadata;
    unset($orderMetadata['payment_data']['checkout_actor']);
    $fixture['order']->forceFill(['metadata' => $orderMetadata])->save();
    $registrationIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $fixture['registrations'],
    );

    expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($fixture['session'], $registrationIds))->toBe([])
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('reverses ticket commissions for cancelled, rejected and refunded registrations', function (): void {
    $eventCases = [
        EventRegistrationCancelled::class => 'cancelled',
        EventRegistrationRejected::class => 'rejected',
        EventRegistrationRefunded::class => 'refunded',
    ];

    foreach ($eventCases as $eventClass => $reason) {
        $fixture = seedTicketAffiliateCommissionCheckout();
        $session = $fixture['session'];
        $registrationIds = array_map(static fn (Registration $registration): string => (string) $registration->getKey(), $fixture['registrations']);
        $session->setStepData('create_event_registrations', ['registration_ids' => $registrationIds]);

        expect(app(RecordTicketAffiliateCommissionsStep::class)->handle($session)->isSuccessful())->toBeTrue();

        event(new $eventClass($fixture['registrations'][0], $reason));

        $conversion = AffiliateConversion::query()
            ->where('external_reference', 'ticket:'.$fixture['order']->getKey().':registration:'.$registrationIds[0])
            ->firstOrFail();

        expect($conversion->reversed_at)->not->toBeNull()
            ->and($conversion->status)->toBeInstanceOf('AIArmada\\Affiliates\\States\\ReversedConversion');
    }
});

it('reverses pending ticket commissions when the event is cancelled', function (): void {
    $fixture = seedTicketAffiliateCommissionCheckout();
    $registrationIds = array_map(
        static fn (Registration $registration): string => (string) $registration->getKey(),
        $fixture['registrations'],
    );
    $event = Event::query()->findOrFail($fixture['registrations'][0]->event_id);
    $event->forceFill(['status' => EventApproved::class])->save();

    expect(app(RecordTicketAffiliateCommissionsAction::class)->handle($fixture['session'], $registrationIds))->toHaveCount(2);

    $moderator = User::factory()->create();
    (new CancelEvent($event, $moderator))->handle();

    foreach ($registrationIds as $registrationId) {
        $conversion = AffiliateConversion::query()
            ->where('external_reference', 'ticket:'.$fixture['order']->getKey().':registration:'.$registrationId)
            ->firstOrFail();

        expect($conversion->status)->toBeInstanceOf(ReversedConversion::class);
    }

    expect($event->fresh()->status->getValue())->toBe('cancelled')
        ->and($fixture['registrations'][0]->fresh()->status->getValue())->toBe('confirmed');
});

it('finishes a registration refund and retries a payout-reserved commission reversal from the share outbox', function (): void {
    $fixture = seedTicketAffiliateCommissionCheckout();
    $registration = $fixture['registrations'][0];
    $registrationIds = [(string) $registration->getKey(), (string) $fixture['registrations'][1]->getKey()];
    app(RecordTicketAffiliateCommissionsAction::class)->handle($fixture['session'], $registrationIds);

    $conversion = AffiliateConversion::query()
        ->where('external_reference', 'ticket:'.$fixture['order']->getKey().':registration:'.$registration->getKey())
        ->firstOrFail();
    $conversion->forceFill([
        'status' => ApprovedConversion::class,
        'approved_at' => now(),
    ])->save();
    $balance = AffiliateBalance::query()
        ->where('affiliate_id', $fixture['affiliate']->getKey())
        ->where('currency', 'MYR')
        ->firstOrFail();
    $balance->forceFill([
        'holding_minor' => 0,
        'available_minor' => (int) $conversion->commission_minor,
        'lifetime_earnings_minor' => (int) $conversion->commission_minor,
    ])->save();
    $payout = CreatePayout::run([(string) $conversion->getKey()]);

    app(RegistrationServiceInterface::class)->refund($registration, 'Customer refund');

    $receipt = ShareEffectReceipt::query()
        ->where('effect_type', 'ticket_affiliate_reversal')
        ->where('source_id', (string) $registration->getKey())
        ->firstOrFail();

    expect($registration->fresh()->status->getValue())->toBe('refunded')
        ->and($conversion->fresh()->status)->toBeInstanceOf(ApprovedConversion::class)
        ->and($receipt->status)->toBe(ShareEffectStatus::Pending);

    UpdatePayoutStatus::run($payout, 'cancelled');
    app(DispatchShareEffectReceiptsAction::class)->handle(50, 'ticket-refund-test');

    expect($conversion->fresh()->status)->toBeInstanceOf(ReversedConversion::class)
        ->and($receipt->fresh()->status)->toBe(ShareEffectStatus::Succeeded);
});

it('ships with the Dawah cash door and affiliate bonus programs disabled', function (): void {
    expect(config('affiliates.payouts.reward_award_settlement.enabled'))->toBeFalse()
        ->and(config('referrals.fulfillment.drivers.affiliate_cash.enabled'))->toBeFalse()
        ->and(config('referrals.owner.enabled'))->toBeFalse()
        ->and(config('affiliates.bonuses.top_performer.enabled'))->toBeFalse()
        ->and(config('affiliates.bonuses.recruitment.enabled'))->toBeFalse()
        ->and(config('affiliates.bonuses.consistency.enabled'))->toBeFalse()
        ->and(config('affiliates.bonuses.growth.enabled'))->toBeFalse();
});
