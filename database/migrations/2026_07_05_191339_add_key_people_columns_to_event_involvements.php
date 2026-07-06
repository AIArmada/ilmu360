<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('events.database.tables.event_involvements', 'event_involvements');

        Schema::table($tableName, function (Blueprint $table) {
            $table->uuid('speaker_id')->nullable()->after('event_id');
            $table->string('name')->nullable()->after('role_code');
            $table->boolean('is_public')->default(true)->after('sort_order');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN involveable_type DROP NOT NULL");
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN involveable_id DROP NOT NULL");
        }
    }

    public function down(): void
    {
        $tableName = config('events.database.tables.event_involvements', 'event_involvements');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn(['speaker_id', 'name', 'is_public']);
        });
    }
};
