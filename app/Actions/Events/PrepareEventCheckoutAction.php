<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\Checkout\Contracts\CheckoutServiceInterface;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Ticketing\Actions\AddTicketTypeToCartAction;
use AIArmada\Ticketing\Models\TicketType;
use App\Models\Event;
use App\Models\User;
use App\Support\Commerce\EventCommerceModes;
use InvalidArgumentException;

/**
 * Builds an account-owned event checkout without making the event domain know
 * which cart, discount, inventory, or payment provider implementation is in use.
 */
final class PrepareEventCheckoutAction
{
    public function __construct(
        private readonly CartManagerInterface $cartManager,
        private readonly AddTicketTypeToCartAction $addTicketTypeToCart,
        private readonly CheckoutServiceInterface $checkoutService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $agreement
     */
    public function handle(
        Event $event,
        TicketType $ticketType,
        int $quantity,
        array $participants,
        User $buyer,
        array $agreement = [],
        ?string $discountCode = null,
        ?string $idempotencyKey = null,
    ): CheckoutSession {
        $this->ensureCheckoutCanStart($event, $ticketType, $quantity, $participants);

        $scopeIds = EventTicketScope::ids($ticketType);
        $currency = $this->resolveCurrency($ticketType);
        $normalizedDiscountCode = $this->normalizeCode($discountCode);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        if ($idempotencyKey !== null) {
            $existing = $this->findExistingCheckout($buyer, $idempotencyKey);

            if ($existing instanceof CheckoutSession) {
                return $existing;
            }
        }

        $cart = $this->cartManager->getCartInstance(
            (string) config('events.features.commerce.checkout.cart_instance', 'event-checkout'),
        );

        // A submit is an intentional new checkout. The named event cart keeps
        // the normal shop cart separate, while clearing this cart prevents an
        // abandoned participant list from being silently combined with a new one.
        $cart->clear();
        $this->addTicketTypeToCart->handle(
            cart: $cart,
            ticketType: $ticketType,
            quantity: $quantity,
            participants: $participants,
            extraAttributes: [
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => $scopeIds['event_occurrence_id'],
                'event_session_id' => $scopeIds['event_session_id'],
                'currency' => $currency,
                'event_fulfillment' => 'event_registration',
                'event_checkout' => [
                    'agreement' => $agreement,
                    'buyer_id' => (string) $buyer->getKey(),
                    'idempotency_key' => $idempotencyKey,
                ],
            ],
        );

        $cart->setMetadataBatch(array_filter([
            'event_id' => (string) $event->getKey(),
            'event_occurrence_id' => $scopeIds['event_occurrence_id'],
            'event_session_id' => $scopeIds['event_session_id'],
            'currency' => $currency,
            // checkout's unified discount resolver classifies this as either a
            // voucher or a promotion when the package evaluates the session.
            'promo_code' => $normalizedDiscountCode,
            'event_checkout' => [
                'agreement' => $agreement,
                'buyer_id' => (string) $buyer->getKey(),
                'idempotency_key' => $idempotencyKey,
            ],
        ], static fn (mixed $value): bool => $value !== null));

        $cartId = $cart->getId();

        if ($cartId === null) {
            throw new InvalidArgumentException('Unable to create the event checkout cart.');
        }

        $session = $this->checkoutService->startCheckout($cartId);
        $paymentData = $session->payment_data ?? [];
        $paymentData['checkout_actor'] = [
            'type' => $buyer->getMorphClass(),
            'id' => (string) $buyer->getKey(),
        ];
        $paymentData['event_checkout'] = [
            'event_id' => (string) $event->getKey(),
            'ticket_type_id' => (string) $ticketType->getKey(),
            'agreement' => $agreement,
            'idempotency_key' => $idempotencyKey,
        ];

        $session->forceFill([
            'currency' => $currency,
            'billing_data' => array_filter([
                'name' => $buyer->name,
                'email' => $buyer->email,
                'phone' => $buyer->phone,
            ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''),
            'selected_payment_gateway' => (string) config(
                'checkout.payment.default_gateway',
                'cashier-chip',
            ),
            'payment_data' => $paymentData,
        ])->save();

        return $session;
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     */
    private function ensureCheckoutCanStart(
        Event $event,
        TicketType $ticketType,
        int $quantity,
        array $participants,
    ): void {
        if (! (bool) config('events.features.commerce.checkout.enabled', true)) {
            throw new InvalidArgumentException('Event checkout is not available.');
        }

        if (! $event->isRegistrationAvailable()) {
            throw new InvalidArgumentException('Registration for this event is closed.');
        }

        $ticketEvent = EventTicketScope::event($ticketType);

        if ($ticketEvent === null || $ticketEvent->isNot($event)) {
            throw new InvalidArgumentException('The selected ticket does not belong to this event.');
        }

        if ($ticketType->status !== 'active' || ! $ticketType->isPubliclyVisible()) {
            throw new InvalidArgumentException('The selected ticket is not available.');
        }

        if ($quantity < 1 || count($participants) !== $quantity) {
            throw new InvalidArgumentException('Each ticket must have one participant in this checkout.');
        }

        $maximum = (int) config('events.features.commerce.checkout.max_participants', 10);

        if ($maximum > 0 && $quantity > $maximum) {
            throw new InvalidArgumentException(sprintf('You can register at most %d participants at a time.', $maximum));
        }

        $price = (int) ($ticketType->price ?? 0);

        if ($price > 0 && ! EventCommerceModes::publicPaidCheckoutEnabled()) {
            throw new InvalidArgumentException('Online ticket payment is not available for this event yet.');
        }

        $this->resolveCurrency($ticketType);
    }

    private function resolveCurrency(TicketType $ticketType): string
    {
        $currency = mb_strtoupper(mb_trim((string) ($ticketType->currency ?: config('checkout.defaults.currency', 'MYR'))));
        $supported = config('events.features.commerce.supported_currencies', ['MYR']);

        if (! is_array($supported) || ! in_array($currency, $supported, true)) {
            throw new InvalidArgumentException(sprintf('The ticket currency [%s] is not supported.', $currency));
        }

        return $currency;
    }

    private function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = mb_strtoupper(mb_trim($code));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeIdempotencyKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $normalized = mb_trim($key);

        if ($normalized === '') {
            throw new InvalidArgumentException('The checkout idempotency key must be a non-empty string.');
        }

        if (mb_strlen($normalized) > 255) {
            throw new InvalidArgumentException('The checkout idempotency key may not exceed 255 characters.');
        }

        return $normalized;
    }

    private function findExistingCheckout(User $buyer, string $idempotencyKey): ?CheckoutSession
    {
        return CheckoutSession::query()
            ->where('payment_data->event_checkout->idempotency_key', $idempotencyKey)
            ->latest('created_at')
            ->get()
            ->first(function (CheckoutSession $session) use ($buyer): bool {
                $actor = data_get($session->payment_data ?? [], 'checkout_actor');

                return is_array($actor)
                    && ($actor['type'] ?? null) === $buyer->getMorphClass()
                    && (string) ($actor['id'] ?? '') === (string) $buyer->getKey();
            });
    }
}
