<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public free-checkout metadata boundary.
 *
 * Registration owns the registration rows, including the checkout batch and
 * agreement evidence recorded on each free registration. The root checkout
 * transport still owns payment/order orchestration and batch-id generation;
 * it passes stable scalar identities here and receives no mutable Eloquent
 * state back.
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
}
