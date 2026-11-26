<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use AIArmada\Affiliates\Contracts\FraudRule;
use AIArmada\Affiliates\Data\ClickFraudEvidence;
use AIArmada\Affiliates\Data\ConversionFraudEvidence;
use AIArmada\Affiliates\Data\FraudFinding;
use AIArmada\Affiliates\Features\Fraud\Api\FraudSeverity;

final class Wp2SignalFraudRule implements FraudRule
{
    public function ruleCode(): string
    {
        return 'WP2_THROW';
    }

    public function analyzeClick(ClickFraudEvidence $evidence): ?FraudFinding
    {
        return null;
    }

    public function analyzeConversion(ConversionFraudEvidence $evidence): FraudFinding
    {
        return new FraudFinding(
            ruleCode: $this->ruleCode(),
            riskPoints: 10,
            severity: FraudSeverity::Low,
            description: 'WP2 injected fraud signal probe',
            evidence: ['wp2' => true],
        );
    }
}
