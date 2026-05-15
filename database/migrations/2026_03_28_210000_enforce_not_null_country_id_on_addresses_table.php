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
        DB::table('addresses')
            ->whereNull('country_id')
            ->update(['country_id' => 132]);

        Schema::table('addresses', function (Blueprint $table): void {
            $table->unsignedBigInteger('country_id')->nullable(false)->change();
        });
    }
};
