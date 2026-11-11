<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public offline/complimentary admission issuance boundary.
 *
 * Callers outside Registration pass stable scalar identities and receive only
 * immutable scalar receipts. The feature owns the organizer-issued admission
 * write path while the orders, ticketing, and events packages remain the
 * canonical external writers for orders, payments, registrations, and passes.
 *
 * `issue` records one organizer-issued admission (cash, bank transfer, or
 * complimentary) through the shared order and registration pipeline. `confirm`
 * completes a pending offline admission and issues its pass. Both return the
 * same receipt shape so the transport adapter can render either path without
 * receiving mutable Eloquent models.
 */
interface AdmissionIssuance
{
    /**
     * @param  array<string, mixed>  $participant
     * @return array{
     *     order_id: string,
     *     registration_id: string,
     *     pass_ids: list<string>,
     *     amount: int,
     *     currency: string
     * }
     */
    public function issue(
        string $eventId,
        string $ticketTypeId,
        array $participant,
        string $actorId,
        string $paymentState = 'confirmed',
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        ?string $notes = null,
    ): array;

    /**
     * @return array{
     *     order_id: string,
     *     registration_id: string,
     *     pass_ids: list<string>,
     *     amount: int,
     *     currency: string
     * }
     */
    public function confirm(
        string $eventId,
        string $orderId,
        string $actorId,
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        ?string $notes = null,
    ): array;
}
