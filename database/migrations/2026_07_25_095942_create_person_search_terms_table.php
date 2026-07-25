<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->index();
            $table->string('term', 120)->index();
            $table->index(['person_id', 'term']);
        });
    }
};
