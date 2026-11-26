<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use AIArmada\Affiliates\Contracts\FraudRule;
use AIArmada\Affiliates\Data\ClickFraudEvidence;
use AIArmada\Affiliates\Data\ConversionFraudEvidence;
use AIArmada\Affiliates\Data\FraudFinding;
use RuntimeException;

final class Wp2ThrowingFraudRule implements FraudRule
{
    public function ruleCode(): string
    {
        return 'WP2_THROW';
    }

    public function analyzeClick(ClickFraudEvidence $evidence): ?FraudFinding
    {
        return null;
    }

    public function analyzeConversion(ConversionFraudEvidence $evidence): ?FraudFinding
    {
        throw new RuntimeException('wp2 injected fraud-stage failure');
    }
}
