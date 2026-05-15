<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type')->index();
            $table->foreignUuid('notifiable_id')->index();
            $table->jsonb('data');
            $table->string('family')->nullable()->index();
            $table->string('trigger')->nullable()->index();
            $table->string('priority')->nullable()->index();
            $table->string('fingerprint')->nullable()->index();
            $table->text('action_url')->nullable();
            $table->string('entity_type')->nullable()->index();
            $table->foreignUuid('entity_id')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->boolean('inbox_visible')->default(true)->index();
            $table->boolean('is_digest')->default(false)->index();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable');
        });

        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->time('digest_delivery_time')->nullable();
            $table->unsignedTinyInteger('digest_weekly_day')->default(1);
            $table->jsonb('preferred_channels')->nullable();
            $table->jsonb('fallback_channels')->nullable();
            $table->string('fallback_strategy')->default('next_available');
            $table->boolean('urgent_override')->default(true)->index();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id'], 'notification_settings_user_unique');
        });

        Schema::create('notification_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();
            $table->string('scope_type')->index();
            $table->string('scope_key')->index();
            $table->boolean('enabled')->default(true)->index();
            $table->string('cadence')->default('instant')->index();
            $table->jsonb('channels')->nullable();
            $table->jsonb('fallback_channels')->nullable();
            $table->boolean('urgent_override')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope_type', 'scope_key'], 'notification_rules_user_scope_unique');
        });

        Schema::create('notification_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();
            $table->string('channel')->index();
            $table->string('address')->nullable();
            $table->string('external_id')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamp('verified_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'address'], 'notification_destinations_user_channel_address_unique');
        });

        Schema::create('notification_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();
            $table->string('fingerprint')->nullable();
            $table->string('family')->index();
            $table->string('trigger')->index();
            $table->string('title');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->string('entity_type')->nullable()->index();
            $table->string('entity_id')->nullable()->index();
            $table->string('priority')->default('medium')->index();
            $table->string('delivery_cadence')->nullable()->index();
            $table->uuid('notification_id')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('read_at')->nullable()->index();
            $table->jsonb('channels_attempted')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint'], 'notification_messages_user_fingerprint_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_message_id')->index();
            $table->foreignUuid('user_id')->index();
            $table->string('family')->index();
            $table->string('trigger')->index();
            $table->string('channel')->index();
            $table->foreignUuid('destination_id')->nullable()->index();
            $table->string('fingerprint')->unique();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->jsonb('payload')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_messages');
        Schema::dropIfExists('notification_destinations');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
