<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credential_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('credentialable_type', 255);
            $table->uuid('credentialable_id');
            $table->foreignUuid('credential_id')->index();
            $table->foreignUuid('issuing_institution_id')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->date('date_obtained')->nullable();
            $table->date('date_expired')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->index(['credentialable_type', 'credentialable_id'], 'credential_assignments_cred_index');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credential_assignments');
    }
};
