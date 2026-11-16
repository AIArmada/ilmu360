<?php

use Illuminate\Support\Facades\Schema;

it('uses UUID columns for OAuth ownership', function () {
    expect(Schema::hasTable('oauth_auth_codes'))->toBeTrue()
        ->and(Schema::hasTable('oauth_access_tokens'))->toBeTrue()
        ->and(Schema::hasTable('oauth_clients'))->toBeTrue()
        ->and(Schema::hasTable('oauth_device_codes'))->toBeTrue()
        ->and(Schema::getColumnType('oauth_auth_codes', 'user_id'))->not->toBe('integer')
        ->and(Schema::getColumnType('oauth_access_tokens', 'user_id'))->not->toBe('integer')
        ->and(Schema::getColumnType('oauth_device_codes', 'user_id'))->not->toBe('integer')
        ->and(Schema::getColumnType('oauth_clients', 'owner_id'))->not->toBe('integer');
});
