<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyNotificationDeliveryParityCommand extends Command
{
    protected $signature = 'communications:verify-parity {--days=7 : Look back window}';

    protected $description = 'Compare delivery counts between legacy and package notification tables';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $legacyCount = DB::table('notification_messages')
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        $packageCount = DB::table('communication_deliveries')
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        $this->table(
            ['Source', 'Count'],
            [
                ['Legacy (notification_messages)', $legacyCount],
                ['Package (communication_deliveries)', $packageCount],
            ]
        );

        if ($legacyCount === $packageCount) {
            $this->info('✅ Parity match between legacy and package notification delivery counts.');
        } else {
            $this->warn("⚠️  Gap: {$legacyCount} legacy vs {$packageCount} package records.");
        }

        return self::SUCCESS;
    }
}
