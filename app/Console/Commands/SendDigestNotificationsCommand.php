<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AIArmada\Communications\Models\CommunicationBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SendDigestNotificationsCommand extends Command
{
    protected $signature = 'communications:send-digests';

    protected $description = 'Send scheduled digest notifications';

    public function handle(): int
    {
        $batches = CommunicationBatch::query()
            ->whereNull('started_at')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where('scheduled_at', '<=', CarbonImmutable::now())
            ->get();

        $this->info("Found {$batches->count()} batches ready for processing.");

        foreach ($batches as $batch) {
            $batch->update(['started_at' => CarbonImmutable::now()]);

            // ponytail: process batch deliveries in Phase 2
            // when per-communication dependencies are resolved

            $batch->update([
                'completed_at' => CarbonImmutable::now(),
                'started_at' => CarbonImmutable::now(),
            ]);
        }

        return self::SUCCESS;
    }
}
