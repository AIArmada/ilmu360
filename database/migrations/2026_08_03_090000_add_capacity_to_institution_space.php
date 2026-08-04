<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('institution_space', 'capacity')) {
            Schema::table('institution_space', function (Blueprint $table): void {
                $table->unsignedInteger('capacity')->nullable()->after('space_id');
            });
        }
    }
};
