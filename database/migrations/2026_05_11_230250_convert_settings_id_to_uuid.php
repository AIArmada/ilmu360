<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add new UUID column
        Schema::table('settings', function (Blueprint $table) {
            $table->uuid('id_new')->nullable()->after('id');
        });

        // Migrate data and generate UUIDs
        DB::table('settings')->update(['id_new' => DB::raw('gen_random_uuid()')]);

        // Drop the old id column and rename new one
        Schema::table('settings', function (Blueprint $table) {
            $table->dropPrimary();
        });

        DB::statement('ALTER TABLE settings DROP COLUMN id');
        DB::statement('ALTER TABLE settings RENAME COLUMN id_new TO id');
        DB::statement('ALTER TABLE settings ADD PRIMARY KEY (id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a destructive migration - cannot safely rollback
        // UUID values cannot be converted back to sequential integers
    }
};
