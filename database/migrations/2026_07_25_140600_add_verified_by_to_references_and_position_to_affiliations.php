<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('references', function (Blueprint $table): void {
            if (! Schema::hasColumn('references', 'verified_by')) {
                $table->foreignUuid('verified_by')->nullable()->index();
            }
        });

        Schema::table('affiliations', function (Blueprint $table): void {
            if (! Schema::hasColumn('affiliations', 'position')) {
                $table->string('position', 50)->nullable()->after('affiliation_type');
            }
        });
    }
};
