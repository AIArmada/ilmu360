<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AffiliateRuntimeDataPurger
{
    /**
     * @return list<string>
     */
    public function purgeTables(): array
    {
        return array_values(array_filter([
            config('affiliates.database.tables.affiliates', 'affiliates'),
            config('affiliates.database.tables.attributions', 'affiliate_attributions'),
            config('affiliates.database.tables.balances', 'affiliate_balances'),
            config('affiliates.database.tables.commission_promotions', 'affiliate_commission_promotions'),
            config('affiliates.database.tables.commission_rules', 'affiliate_commission_rules'),
            config('affiliates.database.tables.commission_templates', 'affiliate_commission_templates'),
            config('affiliates.database.tables.conversions', 'affiliate_conversions'),
            config('affiliates.database.tables.daily_stats', 'affiliate_daily_stats'),
            config('affiliates.database.tables.fraud_signals', 'affiliate_fraud_signals'),
            config('affiliates.database.tables.links', 'affiliate_links'),
            config('affiliates.database.tables.network', 'affiliate_network'),
            config('affiliates.database.tables.payout_events', 'affiliate_payout_events'),
            config('affiliates.database.tables.payout_holds', 'affiliate_payout_holds'),
            config('affiliates.database.tables.payout_methods', 'affiliate_payout_methods'),
            config('affiliates.database.tables.payouts', 'affiliate_payouts'),
            config('affiliates.database.tables.program_creatives', 'affiliate_program_creatives'),
            config('affiliates.database.tables.program_memberships', 'affiliate_program_memberships'),
            config('affiliates.database.tables.programs', 'affiliate_programs'),
            config('affiliates.database.tables.program_tiers', 'affiliate_program_tiers'),
            config('affiliates.database.tables.ranks', 'affiliate_ranks'),
            config('affiliates.database.tables.support_messages', 'affiliate_support_messages'),
            config('affiliates.database.tables.support_tickets', 'affiliate_support_tickets'),
            config('affiliates.database.tables.tax_documents', 'affiliate_tax_documents'),
            config('affiliates.database.tables.touchpoints', 'affiliate_touchpoints'),
            config('affiliates.database.tables.training_modules', 'affiliate_training_modules'),
            config('affiliates.database.tables.training_progress', 'affiliate_training_progress'),
            config('affiliates.database.tables.volume_tiers', 'affiliate_volume_tiers'),
        ]));
    }

    public function purge(bool $force = false): void
    {
        if (! $force && ! $this->shouldPurge()) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->purgeTables() as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->delete();
            }
        });
    }

    private function shouldPurge(): bool
    {
        return (bool) config('dawah-share.runtime_data_purge.enabled', false);
    }
}
