<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use AIArmada\Affiliates\Contracts\FraudRule;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use Illuminate\Http\Request;
use RuntimeException;

final class Wp2LifecycleThrowingRule implements FraudRule
{
    public function ruleCode(): string
    {
        return 'WP2_LIFECYCLE_THROW';
    }

    public function analyzeClick(Affiliate $affiliate, Request $request, array $context): ?AffiliateFraudSignal
    {
        return null;
    }

    public function analyzeConversion(AffiliateConversion $conversion, array $context): ?AffiliateFraudSignal
    {
        throw new RuntimeException('wp2 lifecycle injected failure');
    }
}
