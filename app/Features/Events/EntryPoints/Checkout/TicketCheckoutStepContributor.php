<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Checkout;

use AIArmada\Checkout\Contracts\CheckoutStepInterface;
use AIArmada\Checkout\Contracts\StepContributor;
use Closure;

final readonly class TicketCheckoutStepContributor implements StepContributor
{
    /** @return array<string, Closure(): CheckoutStepInterface> */
    public function steps(): array
    {
        return [
            'record_ticket_affiliate_commissions' => fn (): CheckoutStepInterface => app(RecordTicketAffiliateCommissionsStep::class),
        ];
    }
}
