<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateNotificationRulesCommand extends Command
{
    protected $signature = 'communications:migrate-rules';

    protected $description = 'Migrate notification_rules to communication_preferences';

    public function handle(): int
    {
        $rules = DB::table('notification_rules')->get();
        $count = 0;

        foreach ($rules as $rule) {
            DB::table('communication_preferences')->insert([
                'id' => $rule->id,
                'recipient_type' => 'App\\Models\\User',
                'recipient_id' => $rule->user_id,
                'scope_type' => $rule->scope_type,
                'scope_key' => $rule->scope_key,
                'enabled_at' => $rule->enabled ? now() : null,
                'source' => 'legacy',
                'metadata' => json_encode([
                    'cadence' => $rule->cadence,
                    'channels' => $rule->channels ? json_decode($rule->channels, true) : null,
                    'fallback_channels' => $rule->fallback_channels ? json_decode($rule->fallback_channels, true) : null,
                    'urgent_override' => $rule->urgent_override,
                    'legacy_meta' => $rule->meta ? json_decode($rule->meta, true) : null,
                ]),
                'created_at' => $rule->created_at ?? now(),
                'updated_at' => $rule->updated_at ?? now(),
            ]);
            $count++;
        }

        $this->info("Migrated {$count} notification rules to communication_preferences.");

        return self::SUCCESS;
    }
}
