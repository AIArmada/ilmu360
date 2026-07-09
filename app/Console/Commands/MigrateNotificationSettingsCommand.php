<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AIArmada\Communications\Models\CommunicationPreference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateNotificationSettingsCommand extends Command
{
    protected $signature = 'communications:migrate-settings';

    protected $description = 'Migrate notification_settings data to communication_preferences table';

    public function handle(): int
    {
        $rows = DB::table('notification_settings')->get();

        if ($rows->isEmpty()) {
            $this->info('No notification_settings rows to migrate.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($rows as $row) {
            CommunicationPreference::query()->updateOrCreate(
                [
                    'recipient_type' => 'App\\Models\\User',
                    'recipient_id' => $row->user_id,
                    'channel' => null,
                    'category' => null,
                ],
                [
                    'locale' => $row->locale,
                    'timezone' => $row->timezone,
                    'quiet_hours_start' => $row->quiet_hours_start,
                    'quiet_hours_end' => $row->quiet_hours_end,
                    'enabled_at' => now(),
                    'metadata' => [
                        'preferred_channels' => json_decode((string) $row->preferred_channels, true),
                        'fallback_channels' => json_decode((string) $row->fallback_channels, true),
                        'fallback_strategy' => $row->fallback_strategy,
                        'urgent_override' => (bool) $row->urgent_override,
                        'digest_delivery_time' => $row->digest_delivery_time,
                        'digest_weekly_day' => $row->digest_weekly_day,
                    ],
                ]
            );
            $count++;
        }

        $this->info("Migrated {$count} notification_settings rows to communication_preferences.");

        return self::SUCCESS;
    }
}
