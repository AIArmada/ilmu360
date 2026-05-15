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

            // Bank
            $table->string('bank_code')->nullable();        // normalized code
            $table->string('bank_name')->nullable();        // optional display
            $table->string('account_number')->nullable();

            // DuitNow
            $table->string('duitnow_type')->nullable();     // mobile|nric|business|passport
            $table->string('duitnow_value')->nullable();

            // E-wallet
            $table->string('ewallet_provider')->nullable(); // tng|grab|shopee|boost|etc
            $table->string('ewallet_handle')->nullable();   // phone/email/merchant-id/username
            $table->text('ewallet_qr_payload')->nullable(); // QR text payload

            $table->string('reference_note')->nullable();

            $table->string('status')->default('unverified'); // unverified|verified|rejected|inactive
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->index();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['donatable_type', 'donatable_id', 'method']);
            $table->index(['recipient']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_channels');
    }
};
