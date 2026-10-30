<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\States\Active;
use App\Models\ShareSourceAttributionReceipt;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Committed share-evidence builders for host-integration tests.
 */
final class RewardHostFixtures
{
    public static function guestAffiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'code' => 'GUEST-'.Str::upper(Str::random(12)),
            'name' => 'Guest share identity',
            'purpose' => ShareTrackingProfile::PURPOSE_SHARE_GUEST,
            'metadata' => ShareTrackingProfile::guestMetadata(),
            'status' => Active::class,
            'commission_type' => 'percentage',
            'commission_rate' => 0,
            'currency' => 'MYR',
        ]);
    }

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
