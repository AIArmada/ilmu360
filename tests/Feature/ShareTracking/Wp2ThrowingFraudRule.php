<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use AIArmada\Affiliates\Contracts\FraudRule;
use AIArmada\Affiliates\Enums\FraudSeverity;
use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;

final class Wp2ThrowingFraudRule implements FraudRule
{
    public function ruleCode(): string
    {
        return 'WP2_THROW';
    }

    public function analyzeClick(Affiliate $affiliate, Request $request, array $context): ?AffiliateFraudSignal
    {
        return null;
    }

    public function analyzeConversion(AffiliateConversion $conversion, array $context): ?AffiliateFraudSignal
    {
        // A real persisted signal write first: the rollback proof must
        // cover fraud rows, not just the conversion.
        AffiliateFraudSignal::query()->create([
            'affiliate_id' => $conversion->affiliate_id,
            'conversion_id' => $conversion->getKey(),
            'rule_code' => $this->ruleCode(),
            'risk_points' => 10,
            'severity' => FraudSeverity::Low,
            'description' => 'WP2 injected failure probe',
            'evidence' => ['wp2' => true],
            'status' => FraudSignalStatus::Detected,
            'detected_at' => CarbonImmutable::now(),
        ]);

        throw new RuntimeException('wp2 injected fraud-stage failure');
    }
}
