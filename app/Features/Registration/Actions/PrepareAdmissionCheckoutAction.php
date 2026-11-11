<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateConversion;
use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\Checkout\Contracts\CheckoutServiceInterface;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Ticketing\Actions\AddTicketTypeToCartAction;
use AIArmada\Ticketing\Models\TicketType;
use App\Features\Registration\Api\AdmissionCheckout;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Commerce\EventCommerceModes;
use App\Shared\ShareTracking\ActiveAttribution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owns paid admission checkout preparation.
 *
 * The root checkout transport keeps the Livewire page, the provider payment
 * handoff and the post-payment redirect/result flow. This action resolves
 * caller identities through morph aliases, revalidates the checkout start
 * guards, builds the package cart and checkout session, and freezes the
 * affiliate and share decisions the payment callback inherits. Carts,
 * checkout sessions and affiliate tables stay package-canonical and
 * unclaimed.
 */
final class PrepareAdmissionCheckoutAction implements AdmissionCheckout
{
    public function __construct(
        private readonly CartManagerInterface $cartManager,
        private readonly AddTicketTypeToCartAction $addTicketTypeToCart,
        private readonly CheckoutServiceInterface $checkoutService,
        private readonly ActiveAttribution $activeAttribution,
        private readonly RecordAffiliateConversion $recordAffiliateConversion,
        private readonly RecordFreeCheckoutMetadataAction $freeCheckoutMetadata,
    ) {}

    /**
     * @param  list<string>  $registrationIds
     * @param  array<string, mixed>  $agreement
     */
    public function recordCheckoutMetadata(array $registrationIds, string $batchId, array $agreement): void
    {
        $this->freeCheckoutMetadata->recordCheckoutMetadata(
            registrationIds: $registrationIds,
            batchId: $batchId,
            agreement: $agreement,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $agreement
     * @return array{session_id: string, created: bool, status: string, payment_redirect_url: string|null}
     */
    public function prepare(
        string $eventId,
        string $ticketTypeId,
        int $quantity,
        array $participants,
        string $buyerId,
        array $agreement = [],
        ?string $discountCode = null,
        ?string $idempotencyKey = null,
        ?object $request = null,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        /** @var Model $buyer */
        $buyer = $userClass::query()->findOrFail($buyerId);

        $ticketType = TicketType::query()->with('ticketable')->findOrFail($ticketTypeId);

        $session = $this->handle(
            event: $event,
            ticketType: $ticketType,
            quantity: $quantity,
            participants: $participants,
            buyer: $buyer,
            agreement: $agreement,
            discountCode: $discountCode,
            idempotencyKey: $idempotencyKey,
            request: $request,
        );

        return [
            'session_id' => (string) $session->getKey(),
            'created' => $session->wasRecentlyCreated,
            'status' => $session->status->name(),
            'payment_redirect_url' => $session->payment_redirect_url,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $agreement
     */
    private function handle(
        PackageEvent $event,
        TicketType $ticketType,
        int $quantity,
        array $participants,
        Model $buyer,
        array $agreement = [],
        ?string $discountCode = null,
        ?string $idempotencyKey = null,
        ?object $request = null,
    ): CheckoutSession {
        $this->ensureCheckoutCanStart($event, $ticketType, $quantity, $participants);

        $scopeIds = EventTicketScope::ids($ticketType);
        $currency = $this->resolveCurrency($ticketType);
        $normalizedDiscountCode = $this->normalizeCode($discountCode);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $event,
            $ticketType,
            $quantity,
            $participants,
            $buyer,
            $agreement,
            $normalizedDiscountCode,
            $idempotencyKey,
            $scopeIds,
            $currency,
            $request,
        ): CheckoutSession {
            $userClass = MorphModelResolver::resolve('user');
            $userClass::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = $this->findExistingCheckout($buyer, $idempotencyKey);

                if ($existing instanceof CheckoutSession) {
                    return $existing;
                }
            }

            return $this->createCheckoutSession(
                event: $event,
                ticketType: $ticketType,
                quantity: $quantity,
                participants: $participants,
                buyer: $buyer,
                agreement: $agreement,
                normalizedDiscountCode: $normalizedDiscountCode,
                idempotencyKey: $idempotencyKey,
                scopeIds: $scopeIds,
                currency: $currency,
                request: $request,
            );
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $agreement
     * @param  array<string, mixed>  $scopeIds
     */
    private function createCheckoutSession(
        PackageEvent $event,
        TicketType $ticketType,
        int $quantity,
        array $participants,
        Model $buyer,
        array $agreement,
        ?string $normalizedDiscountCode,
        ?string $idempotencyKey,
        array $scopeIds,
        string $currency,
        ?object $request,
    ): CheckoutSession {
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

        // Freeze the commercial affiliate decision with the same server-created
        // checkout intent as share provenance. The order listener sees the
        // registration_lines marker and leaves ticket commissions to the
        // registration-aware checkout step below.
        $affiliateAttribution = $this->recordAffiliateConversion->resolveAttributionFor($cart);
        $session = $this->checkoutService->startCheckout($cartId);

        // The checkout session is the authoritative server-created paid
        // intent. Freeze the participant attribution decision here, before
        // the external payment handoff, so payment-confirmation callbacks
        // inherit this persisted decision instead of reading callback
        // request cookies or a late token.
        $attributionId = $this->activeAttribution->resolveId($request);
        $occurredAt = $session->created_at instanceof CarbonInterface ? $session->created_at : now();

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
        $paymentData['share_provenance'] = [
            'intent_id' => (string) $session->getKey(),
            'attribution_id' => $attributionId,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];
        $paymentData['affiliate_commission'] = [
            'strategy' => 'registration_lines',
            'attribution_id' => $affiliateAttribution?->getKey(),
            'affiliate_id' => $affiliateAttribution?->affiliate_id,
            'affiliate_program_id' => $affiliateAttribution?->affiliate_program_id,
            'owner_type' => $affiliateAttribution?->owner_type,
            'owner_id' => $affiliateAttribution?->owner_id,
            'commercial_purpose' => $affiliateAttribution?->affiliate?->isCommercialPurpose() ?? false,
            'captured_at' => $occurredAt->toIso8601String(),
        ];

        $session->forceFill([
            'currency' => $currency,
            'billing_data' => array_filter([
                'name' => $buyer->getAttribute('name'),
                'email' => $buyer->getAttribute('email'),
                'phone' => $buyer->getAttribute('phone'),
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
        PackageEvent $event,
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

    private function findExistingCheckout(Model $buyer, string $idempotencyKey): ?CheckoutSession
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
