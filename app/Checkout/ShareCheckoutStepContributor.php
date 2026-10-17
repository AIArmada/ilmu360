<?php

declare(strict_types=1);

namespace App\Checkout;

use AIArmada\Checkout\Contracts\CheckoutStepInterface;
use AIArmada\Checkout\Contracts\StepContributor;
use Closure;

final readonly class ShareCheckoutStepContributor implements StepContributor
{
    /** @return array<string, Closure(): CheckoutStepInterface> */
    public function steps(): array
    {
        return [
            'record_checkout_share_provenance' => fn (): CheckoutStepInterface => app(RecordCheckoutShareProvenanceStep::class),
            'record_ticket_affiliate_commissions' => fn (): CheckoutStepInterface => app(RecordTicketAffiliateCommissionsStep::class),
        ];
    }
}
