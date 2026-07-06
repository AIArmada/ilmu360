<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('events.database.tables.event_attendances', 'event_attendances');

        Schema::table($tableName, function (Blueprint $table) {
            $table->uuid('verified_by_user_id')->nullable()->after('check_in_source');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN event_occurrence_id DROP NOT NULL");
        }
    }

    public function down(): void
    {
        $tableName = config('events.database.tables.event_attendances', 'event_attendances');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('verified_by_user_id');
        });
    }
};
