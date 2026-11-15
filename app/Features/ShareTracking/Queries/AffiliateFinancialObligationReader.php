<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Queries;

use AIArmada\Affiliates\Models\Affiliate;
use App\Features\ShareTracking\Api\FinancialObligationReader as FinancialObligationReaderContract;
use Illuminate\Database\Eloquent\Builder;

final readonly class AffiliateFinancialObligationReader implements FinancialObligationReaderContract
{
    public function hasNonConversionFinancialRecords(string $affiliateId): bool
    {
        $affiliate = new Affiliate;
        $affiliate->setAttribute($affiliate->getKeyName(), $affiliateId);

        if ($affiliate->payouts()->exists()
            || $affiliate->payoutMethods()->exists()
            || $affiliate->payoutHolds()->exists()) {
            return true;
        }

        return $affiliate->balances()
            ->where(function (Builder $query): void {
                $query->where('holding_minor', '!=', 0)
                    ->orWhere('available_minor', '!=', 0)
                    ->orWhere('lifetime_earnings_minor', '!=', 0);
            })
            ->exists();
    }
}
