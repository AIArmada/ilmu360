<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use AIArmada\Affiliates\Models\AffiliateConversion;
use App\Models\ShareSourceAttributionReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Committed share-evidence builders for host-integration tests.
 */
final class RewardHostFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function conversion(string $businessKey, array $overrides = []): AffiliateConversion
    {
        return AffiliateConversion::query()->create(array_merge([
            'affiliate_id' => (string) Str::uuid(),
            'affiliate_code' => 'HOST-'.Str::upper(Str::random(8)),
            'conversion_type' => 'signup',
            'idempotency_key' => hash('sha256', $businessKey),
            'commission_currency' => 'MYR',
            'status' => 'approved',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'approved_at' => CarbonImmutable::now('UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function receipt(string $businessKey, string $transitionId, array $overrides = []): ShareSourceAttributionReceipt
    {
        return ShareSourceAttributionReceipt::factory()->create(array_merge([
            'business_key' => $businessKey,
            'source_transition_id' => $transitionId,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour(),
        ], $overrides));
    }
}
