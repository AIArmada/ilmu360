<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('events.database.tables.event_updates', 'event_updates');

        Schema::table($tableName, function (Blueprint $table) {
            $table->uuid('replacement_event_id')->nullable()->after('event_change_log_id');
            $table->text('notes')->nullable()->after('message');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN title SET DEFAULT ''");
        }
    }

    public function down(): void
    {
        $tableName = config('events.database.tables.event_updates', 'event_updates');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn(['replacement_event_id', 'notes']);
        });
    }
};
