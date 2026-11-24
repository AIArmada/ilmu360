<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('donatable');

            $table->string('label')->nullable();
            $table->string('recipient');

            // bank_account | duitnow | ewallet
            $table->string('method');

            // Currency
            $table->string('currency', 3)->nullable();

            // Bank
            $table->string('bank_code')->nullable();        // normalized code
            $table->string('bank_name')->nullable();        // optional display
            $table->text('account_number')->nullable();
            $table->text('iban')->nullable();
            $table->string('swift_bic', 11)->nullable();
            $table->string('routing_code', 20)->nullable(); // local clearing / branch code where the country's banks use one

            // DuitNow
            $table->string('duitnow_type')->nullable();     // mobile|nric|business|passport
            $table->text('duitnow_value')->nullable();

            // E-wallet
            $table->string('ewallet_provider')->nullable(); // tng|grab|shopee|boost|etc
            $table->string('ewallet_handle')->nullable();   // phone/email/merchant-id/username
            $table->text('ewallet_qr_payload')->nullable(); // QR text payload

            $table->string('reference_note')->nullable();

            $table->string('status')->default('pending'); // pending|verified|rejected|inactive
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->index();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['donatable_type', 'donatable_id', 'method']);
            $table->index(['recipient']);
            $table->index(['status']);
        });

        Schema::create('donation_bank_catalog_initializations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->unique();
        });

        Schema::create('donation_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id'); // canonical addressing country UUID

            $table->string('code', 32);                 // stable FPX / BI-FAST / FAST clearing code
            $table->string('name');                     // catalog display name
            $table->text('source_url');                 // authoritative provenance

            $table->string('status', 16)->default('active'); // active|retired
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('reactivated_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();

            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index(['country_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_banks');
        Schema::dropIfExists('donation_bank_catalog_initializations');
        Schema::dropIfExists('donation_channels');
    }
};
