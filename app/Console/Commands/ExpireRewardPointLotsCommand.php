<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rewards\ExpireRewardPointsAction;
use App\Models\RewardPointLot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExpireRewardPointLotsCommand extends Command
{
    protected $signature = 'rewards:expire-point-lots {--as-of= : Expiry timestamp (UTC, defaults to now)}';

    protected $description = 'Expire due point lots and release their reservations as expiry movements.';

    public function handle(ExpireRewardPointsAction $expire): int
    {
        $asOf = $this->option('as-of');
        $at = CarbonImmutable::now('UTC');

        if (is_string($asOf) && $asOf !== '') {
            try {
                $at = CarbonImmutable::parse($asOf)->setTimezone('UTC');
            } catch (\Throwable) {
                $this->error('Expiry timestamp (--as-of) must be parseable.');

                return self::INVALID;
            }
        }

        $scopes = RewardPointLot::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->whereNull('expired_at')
            ->distinct()
            ->get(['recipient_type', 'recipient_id', 'family_key', 'unit']);

        $movements = 0;

        foreach ($scopes as $scope) {
            $movements += count($expire->handle(
                (string) $scope->recipient_type,
                (string) $scope->recipient_id,
                (string) $scope->family_key,
                (string) $scope->unit,
                $at,
                'lot-expiry',
                'system',
                null,
            ));
        }

        $this->info("Expired {$movements} lot movements across {$scopes->count()} scopes.");

        return self::SUCCESS;
    }
}
