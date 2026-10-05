<?php

declare(strict_types=1);

use App\Services\ShareTracking\AffiliateRuntimeDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('skips the destructive purge unless explicitly enabled', function (): void {
    $affiliateTable = config('affiliates.database.tables.affiliates', 'affiliate_affiliates');

    DB::table($affiliateTable)->insert([
        'id' => (string) Str::uuid(),
        'code' => 'KEEP-001',
        'handle' => 'keep-affiliate',
        'name' => 'Keep Affiliate',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config(['dawah-share.runtime_data_purge.enabled' => false]);

    app(AffiliateRuntimeDataPurger::class)->purge();

    expect(DB::table($affiliateTable)->where('code', 'KEEP-001')->exists())->toBeTrue();
});

it('purges configured affiliate runtime data before launch', function (): void {
    $now = now();

    $affiliateId = (string) Str::uuid();
    $affiliateTable = config('affiliates.database.tables.affiliates', 'affiliate_affiliates');
    $uplineTable = config('affiliates.database.tables.upline', 'affiliate_upline');

    DB::table($affiliateTable)->insert([
        'id' => $affiliateId,
        'code' => 'AFF-001',
        'handle' => 'temporary-affiliate',
        'name' => 'Temporary affiliate',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table($uplineTable)->insert([
        'id' => (string) Str::uuid(),
        'ancestor_id' => $affiliateId,
        'descendant_id' => (string) Str::uuid(),
        'depth' => 1,
    ]);

    app(AffiliateRuntimeDataPurger::class)->purge(force: true);

    expect(DB::table($affiliateTable)->count())->toBe(0)
        ->and(DB::table($uplineTable)->count())->toBe(0);
});
