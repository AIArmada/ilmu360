<?php

declare(strict_types=1);

use App\Services\ShareTracking\AffiliateRuntimeDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('skips the destructive purge unless explicitly enabled', function (): void {
    $affiliateTable = config('affiliates.database.tables.affiliates', 'affiliates');

    DB::table($affiliateTable)->insert([
        'id' => (string) Str::uuid(),
        'code' => 'KEEP-001',
        'name' => 'Keep Affiliate',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config(['dawah-share.runtime_data_purge.enabled' => false]);

    app(AffiliateRuntimeDataPurger::class)->purge();

    expect(DB::table($affiliateTable)->where('code', 'KEEP-001')->exists())->toBeTrue();
});

it('purges the full disposable affiliate package dataset before launch', function (): void {
    $now = now();

    $affiliateId = (string) Str::uuid();
    $programId = (string) Str::uuid();
    $membershipId = (string) Str::uuid();
    $rankId = (string) Str::uuid();
    $programTierId = (string) Str::uuid();
    $creativeId = (string) Str::uuid();
    $ruleId = (string) Str::uuid();
    $volumeTierId = (string) Str::uuid();
    $promotionId = (string) Str::uuid();
    $moduleId = (string) Str::uuid();
    $progressId = (string) Str::uuid();
    $taxDocumentId = (string) Str::uuid();
    $templateId = (string) Str::uuid();
    $networkId = (string) Str::uuid();
    $balanceId = (string) Str::uuid();
    $payoutMethodId = (string) Str::uuid();
    $payoutHoldId = (string) Str::uuid();
    $attributionId = (string) Str::uuid();
    $conversionId = (string) Str::uuid();
    $linkId = (string) Str::uuid();
    $ticketId = (string) Str::uuid();
    $messageId = (string) Str::uuid();

    $seededTables = [
        config('affiliates.database.tables.affiliates', 'affiliates') => [
            'id' => $affiliateId,
            'code' => 'AFF-001',
            'name' => 'Temp Affiliate',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.attributions', 'affiliate_attributions') => [
            'id' => $attributionId,
            'affiliate_id' => $affiliateId,
            'affiliate_code' => 'AFF-001',
            'cookie_value' => 'cookie-001',
            'landing_url' => 'https://example.test',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_cookie_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.balances', 'affiliate_balances') => [
            'id' => $balanceId,
            'affiliate_id' => $affiliateId,
            'currency' => 'USD',
            'minimum_payout_minor' => 5000,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.commission_promotions', 'affiliate_commission_promotions') => [
            'id' => $promotionId,
            'name' => 'Launch promo',
            'bonus_type' => 'percentage',
            'bonus_value' => 250,
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.commission_rules', 'affiliate_commission_rules') => [
            'id' => $ruleId,
            'name' => 'Always on',
            'rule_type' => 'default',
            'conditions' => json_encode(['always' => true]),
            'commission_type' => 'percentage',
            'commission_value' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.commission_templates', 'affiliate_commission_templates') => [
            'id' => $templateId,
            'name' => 'Default template',
            'slug' => 'default-template',
            'rules' => json_encode(['commission' => 1000]),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.conversions', 'affiliate_conversions') => [
            'id' => $conversionId,
            'affiliate_id' => $affiliateId,
            'affiliate_code' => 'AFF-001',
            'subtotal_minor' => 1000,
            'total_minor' => 1000,
            'commission_minor' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.network', 'affiliate_network') => [
            'id' => $networkId,
            'ancestor_id' => (string) Str::uuid(),
            'descendant_id' => (string) Str::uuid(),
            'depth' => 1,
        ],
        config('affiliates.database.tables.payout_methods', 'affiliate_payout_methods') => [
            'id' => $payoutMethodId,
            'affiliate_id' => $affiliateId,
            'type' => 'bank_transfer',
            'details' => 'Test method',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.payout_holds', 'affiliate_payout_holds') => [
            'id' => $payoutHoldId,
            'affiliate_id' => $affiliateId,
            'reason' => 'Manual review',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.programs', 'affiliate_programs') => [
            'id' => $programId,
            'name' => 'Launch program',
            'slug' => 'launch-program',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.program_creatives', 'affiliate_program_creatives') => [
            'id' => $creativeId,
            'program_id' => $programId,
            'type' => 'banner',
            'name' => 'Launch banner',
            'asset_url' => 'https://example.test/banner.png',
            'destination_url' => 'https://example.test/landing',
            'tracking_code' => 'TRACK-001',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.program_memberships', 'affiliate_program_memberships') => [
            'id' => $membershipId,
            'affiliate_id' => $affiliateId,
            'program_id' => $programId,
            'status' => 'active',
            'applied_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.program_tiers', 'affiliate_program_tiers') => [
            'id' => $programTierId,
            'program_id' => $programId,
            'name' => 'Tier 1',
            'level' => 1,
            'commission_rate_basis_points' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.ranks', 'affiliate_ranks') => [
            'id' => $rankId,
            'name' => 'Starter',
            'slug' => 'starter',
            'level' => 1,
            'commission_rate_basis_points' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.support_tickets', 'affiliate_support_tickets') => [
            'id' => $ticketId,
            'affiliate_id' => $affiliateId,
            'subject' => 'Question',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.support_messages', 'affiliate_support_messages') => [
            'id' => $messageId,
            'ticket_id' => $ticketId,
            'affiliate_id' => $affiliateId,
            'message' => 'Hello',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.tax_documents', 'affiliate_tax_documents') => [
            'id' => $taxDocumentId,
            'affiliate_id' => $affiliateId,
            'document_type' => '1099',
            'tax_year' => 2026,
            'total_amount_minor' => 2500,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.training_modules', 'affiliate_training_modules') => [
            'id' => $moduleId,
            'title' => 'Getting started',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.training_progress', 'affiliate_training_progress') => [
            'id' => $progressId,
            'affiliate_id' => $affiliateId,
            'module_id' => $moduleId,
            'progress_percent' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        config('affiliates.database.tables.volume_tiers', 'affiliate_volume_tiers') => [
            'id' => $volumeTierId,
            'name' => 'Volume tier',
            'min_volume_minor' => 10000,
            'commission_rate_basis_points' => 1250,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ];

    foreach ($seededTables as $table => $row) {
        DB::table($table)->insert($row);
    }

    app(AffiliateRuntimeDataPurger::class)->purge(force: true);

    foreach (array_keys($seededTables) as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});
