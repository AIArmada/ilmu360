<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            if (! Schema::hasColumn('venues', 'verified_at')) {
                $table->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('venues', 'last_state_change_at')) {
                $table->timestampTz('last_state_change_at')->nullable();
            }
        });

        Schema::table('references', function (Blueprint $table): void {
            if (! Schema::hasColumn('references', 'verified_at')) {
                $table->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'rejected_at')) {
                $table->timestampTz('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'published_at')) {
                $table->timestampTz('published_at')->nullable();
            }
            if (! Schema::hasColumn('references', 'last_state_change_at')) {
                $table->timestampTz('last_state_change_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        //
    }
};
