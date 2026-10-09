<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_funding_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('program_id')->index();
            $table->char('currency', 3)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('source_ref', 191);
            $table->foreignUuid('source_id')->index();
            $table->json('evidence')->nullable();
            $table->string('funded_by_type', 32);
            $table->string('funded_by_id', 191)->nullable();
            $table->string('funded_by_role', 16);
            $table->timestampTz('funded_at');
            $table->timestampsTz();
            $table->unique(['program_id', 'source_ref']);
        });

        Schema::create('reward_budget_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('movement_key', 191)->unique();
            $table->uuid('program_id')->index();
            $table->char('currency', 3)->index();
            $table->string('kind', 16)->index();
            $table->uuid('award_id')->nullable()->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_role', 16);
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->index(['program_id', 'currency', 'kind']);
        });

        Schema::create('reward_payees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 191);
            $table->string('recipient_id', 191);
            $table->string('method', 32)->index();
            $table->string('account_ref', 191);
            $table->string('account_display', 191);
            $table->string('status', 16)->index();
            $table->json('evidence')->nullable();
            $table->string('verified_by', 191)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->index(['recipient_type', 'recipient_id', 'status']);
        });

        Schema::create('reward_payout_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('award_id')->unique();
            $table->uuid('payee_id')->index();
            $table->uuid('program_id')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->string('fee_payer', 16);
            $table->string('processor', 64);
            $table->string('operation_key', 191)->unique();
            $table->string('first_reference', 64)->unique();
            $table->uuid('affiliate_payout_id')->nullable()->index();
            $table->string('backing_conversion_id', 191)->nullable();
            $table->string('status', 16)->index();
            $table->string('provider_reference', 191)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->json('payee_snapshot');
            $table->json('evidence')->nullable();
            $table->string('requested_by_type', 32);
            $table->string('requested_by_id', 191)->nullable();
            $table->string('requested_by_role', 16);
            $table->string('approved_by', 191)->nullable();
            $table->string('confirmed_by', 191)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_payout_requests');
        Schema::dropIfExists('reward_payees');
        Schema::dropIfExists('reward_budget_movements');
        Schema::dropIfExists('reward_funding_entries');
    }
};
