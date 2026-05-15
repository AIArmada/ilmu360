<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'daily_prayer_institution_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignUuid('daily_prayer_institution_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('users', 'friday_prayer_institution_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignUuid('friday_prayer_institution_id')->nullable()->index();
            });
        }
    }
};
