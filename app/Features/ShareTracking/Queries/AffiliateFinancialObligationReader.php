<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Queries;

use AIArmada\Affiliates\Features\Conversions\Models\AffiliateBalance;
use AIArmada\Affiliates\Features\Identity\Models\Affiliate;
use AIArmada\Affiliates\Features\Payouts\Models\AffiliatePayout;
use AIArmada\Affiliates\Features\Payouts\Models\AffiliatePayoutHold;
use AIArmada\Affiliates\Features\Payouts\Models\AffiliatePayoutMethod;
use App\Features\ShareTracking\Api\FinancialObligationReader as FinancialObligationReaderContract;
use Illuminate\Database\Eloquent\Builder;

final readonly class AffiliateFinancialObligationReader implements FinancialObligationReaderContract
{
    public function hasNonConversionFinancialRecords(string $affiliateId): bool
    {
        $affiliateMorphType = (new Affiliate)->getMorphClass();

        if (AffiliatePayout::query()
            ->where('payee_type', $affiliateMorphType)
            ->where('payee_id', $affiliateId)
            ->exists()
            || AffiliatePayoutMethod::query()->where('affiliate_id', $affiliateId)->exists()
            || AffiliatePayoutHold::query()->where('affiliate_id', $affiliateId)->exists()) {
            return true;
        }

        return AffiliateBalance::query()
            ->where('affiliate_id', $affiliateId)
            ->where(function (Builder $query): void {
                $query->where('holding_minor', '!=', 0)
                    ->orWhere('available_minor', '!=', 0)
                    ->orWhere('lifetime_earnings_minor', '!=', 0);
            })
            ->exists();
    }
}
