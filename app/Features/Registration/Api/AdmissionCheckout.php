<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public event-checkout boundary.
 *
 * Registration owns the admission rows, including the checkout batch and
 * agreement evidence recorded on each free registration, and prepares the
 * paid checkout intent before the root transport hands payment to the active
 * provider. Carts, checkout sessions and affiliate tables stay
 * package-canonical and unclaimed. Callers pass stable scalar identities and
 * receive only immutable scalar results; the root transport keeps the
 * payment-provider handoff, redirect and result flow.
 */
interface AdmissionCheckout
{
    /**
     * Merge the checkout batch id and agreement payload into every listed
     * registration's metadata without replacing unrelated metadata.
     *
     * @param  list<string>  $registrationIds
     * @param  array<string, mixed>  $agreement
     */
    public function recordCheckoutMetadata(array $registrationIds, string $batchId, array $agreement): void;

    /**
     * Prepare the package cart and checkout session for a paid admission.
     *
     * `created` reports whether this call started a new durable session or
     * replayed an existing idempotent one. `status` is the current checkout
     * state name; `payment_redirect_url` is the provider redirect, when the
     * package has already produced one.
     *
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
    ): array;
}
