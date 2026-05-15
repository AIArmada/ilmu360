<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('references', 'parent_reference_id')) {
            Schema::table('references', function (Blueprint $table): void {
                $table->foreignUuid('parent_reference_id')->nullable()->index()->after('id');
            });
        }

        if (! Schema::hasColumn('references', 'part_type')) {
            Schema::table('references', function (Blueprint $table): void {
                $table->string('part_type')->nullable()->after('type');
            });
        }

        if (! Schema::hasColumn('references', 'part_number')) {
            Schema::table('references', function (Blueprint $table): void {
                $table->string('part_number')->nullable()->after('part_type');
            });
        }

        if (! Schema::hasColumn('references', 'part_label')) {
            Schema::table('references', function (Blueprint $table): void {
                $table->string('part_label')->nullable()->after('part_number');
            });
        }
    }
};
