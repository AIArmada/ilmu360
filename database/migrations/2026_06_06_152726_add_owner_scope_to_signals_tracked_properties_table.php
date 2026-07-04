<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('signals.database.tables.tracked_properties', 'signal_tracked_properties');

        if (! Schema::hasColumn($tableName, 'owner_scope')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('owner_scope')->default('global')->after('owner_id');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $legacyIndex = 'signal_tracked_properties_owner_type_owner_id_slug_unique';

            if (Schema::hasColumn($tableName, 'owner_scope')) {
                if (DB::getDriverName() !== 'pgsql') {
                    return;
                }

                $indexes = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$tableName]);
                $indexNames = array_column($indexes, 'indexname');

                if (in_array($legacyIndex, $indexNames, true)) {
                    $table->dropUnique($legacyIndex);
                }

                if (! in_array('signal_tracked_properties_owner_scope_slug_unique', $indexNames, true)) {
                    $table->unique(['owner_scope', 'slug']);
                }
            }
        });
    }
};
