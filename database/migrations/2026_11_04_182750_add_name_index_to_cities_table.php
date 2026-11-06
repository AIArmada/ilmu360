<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('cities')) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS cities_name_index ON cities (name)');
    }
};
