<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('institutions')
            ->where('status', 'unverified')
            ->update([
                'status' => 'pending',
                'last_state_change_at' => $now,
                'updated_at' => $now,
            ]);
    }
};
