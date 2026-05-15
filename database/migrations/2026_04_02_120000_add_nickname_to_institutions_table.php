<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('institutions', 'nickname')) {
            Schema::table('institutions', function (Blueprint $table): void {
                $table->string('nickname')->nullable()->after('name');
            });
        }
    }
};
