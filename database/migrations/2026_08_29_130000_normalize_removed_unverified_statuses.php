<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('donation_channels')) {
            DB::table('donation_channels')
                ->where('status', 'unverified')
                ->update([
                    'status' => 'pending',
                    'last_state_change_at' => $now,
                    'updated_at' => $now,
                ]);

            Schema::table('donation_channels', function (Blueprint $table): void {
                $table->string('status')->default('pending')->change();
            });
        }

        if (Schema::hasTable('venues')) {
            $updates = [
                'status' => 'pending',
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('venues', 'last_state_change_at')) {
                $updates['last_state_change_at'] = $now;
            }

            DB::table('venues')
                ->where('status', 'unverified')
                ->update($updates);
        }
    }
};
