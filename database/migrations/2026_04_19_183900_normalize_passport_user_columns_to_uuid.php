<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->normalizeOauthAuthCodesUserId();
        $this->normalizeOauthAccessTokensUserId();
        $this->normalizeOauthDeviceCodesUserId();
    }

    private function normalizeOauthAuthCodesUserId(): void
    {
        if (! $this->needsUuidUserId('oauth_auth_codes')) {
            return;
        }

        DB::table('oauth_auth_codes')->delete();
        DB::statement('ALTER TABLE oauth_auth_codes ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
    }

    private function normalizeOauthAccessTokensUserId(): void
    {
        if (! $this->needsUuidUserId('oauth_access_tokens')) {
            return;
        }

        $accessTokenIds = DB::table('oauth_access_tokens')
            ->whereNotNull('user_id')
            ->pluck('id')
            ->all();

        if ($accessTokenIds !== []) {
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $accessTokenIds)
                ->delete();
        }

        DB::table('oauth_access_tokens')->whereNotNull('user_id')->delete();
        DB::statement('ALTER TABLE oauth_access_tokens ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
    }

    private function normalizeOauthDeviceCodesUserId(): void
    {
        if (! $this->needsUuidUserId('oauth_device_codes')) {
            return;
        }

        DB::table('oauth_device_codes')->whereNotNull('user_id')->delete();
        DB::statement('ALTER TABLE oauth_device_codes ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
    }

    private function needsUuidUserId(string $table): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return false;
        }

        return Schema::getColumnType($table, 'user_id') !== 'uuid';
    }
};
