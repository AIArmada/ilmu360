<?php

use App\Actions\Membership\AddMemberToSubject;
use App\Models\Institution;
use App\Models\User;
use App\Support\Mcp\McpTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('normalizes missing optional account settings fields as empty strings', function () {
    $user = User::factory()->create([
        'name' => 'Blank Profile User',
        'email' => 'blank-profile@example.test',
        'phone' => null,
        'timezone' => null,
        'daily_prayer_institution_id' => null,
        'friday_prayer_institution_id' => null,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.account-settings.show'));

    $response->assertOk();

    expect($response->json('data.profile'))->toEqual([
        'name' => 'Blank Profile User',
        'email' => 'blank-profile@example.test',
        'phone' => '',
        'timezone' => '',
        'daily_prayer_institution_id' => '',
        'friday_prayer_institution_id' => '',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
});

it('shows the authenticated account settings profile contract', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Harian API',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Jumaat API',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'name' => 'API Settings User',
        'email' => 'settings@example.test',
        'phone' => '+60112223344',
        'timezone' => 'Asia/Kuala_Lumpur',
        'daily_prayer_institution_id' => $dailyInstitution->id,
        'friday_prayer_institution_id' => $fridayInstitution->id,
        'email_verified_at' => now()->subHour()->startOfSecond(),
        'phone_verified_at' => now()->subMinutes(20)->startOfSecond(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.client.account-settings.show'));

    $response->assertOk()
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    expect($response->json('data.profile'))->toEqual([
        'name' => 'API Settings User',
        'email' => 'settings@example.test',
        'phone' => '+60112223344',
        'timezone' => 'Asia/Kuala_Lumpur',
        'daily_prayer_institution_id' => $dailyInstitution->id,
        'friday_prayer_institution_id' => $fridayInstitution->id,
        'email_verified_at' => $user->fresh()->email_verified_at?->toIso8601String(),
        'phone_verified_at' => $user->fresh()->phone_verified_at?->toIso8601String(),
    ]);
});

it('returns the updated account settings profile payload after saving', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Harian Baru',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Jumaat Baru',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'name' => 'Original User',
        'email' => 'original@example.test',
        'phone' => '+60117778899',
        'timezone' => 'UTC',
        'email_verified_at' => now()->subDay()->startOfSecond(),
        'phone_verified_at' => now()->subHours(12)->startOfSecond(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.client.account-settings.update'), [
        'name' => 'Updated API User',
        'email' => 'original@example.test',
        'phone' => '+60117778899',
        'timezone' => 'Asia/Kuala_Lumpur',
        'daily_prayer_institution_id' => $dailyInstitution->id,
        'friday_prayer_institution_id' => $fridayInstitution->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', __('Account settings updated.'))
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $user->refresh();

    expect($response->json('data.profile'))->toEqual([
        'name' => 'Updated API User',
        'email' => 'original@example.test',
        'phone' => '+60117778899',
        'timezone' => 'Asia/Kuala_Lumpur',
        'daily_prayer_institution_id' => $dailyInstitution->id,
        'friday_prayer_institution_id' => $fridayInstitution->id,
        'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
    ]);

    expect($user->name)->toBe('Updated API User')
        ->and($user->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($user->daily_prayer_institution_id)->toBe($dailyInstitution->id)
        ->and($user->friday_prayer_institution_id)->toBe($fridayInstitution->id);
});

it('preserves omitted account settings fields during sparse updates', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Harian Asal',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Jumaat Asal',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'name' => 'Sparse User',
        'email' => 'sparse@example.test',
        'phone' => '+60123334444',
        'timezone' => 'Asia/Kuala_Lumpur',
        'daily_prayer_institution_id' => $dailyInstitution->id,
        'friday_prayer_institution_id' => $fridayInstitution->id,
        'email_verified_at' => now()->subDay()->startOfSecond(),
        'phone_verified_at' => now()->subHours(12)->startOfSecond(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->putJson(route('api.client.account-settings.update'), [
        'name' => 'Sparse User Updated',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', __('Account settings updated.'))
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $user->refresh();

    expect($user->name)->toBe('Sparse User Updated')
        ->and($user->email)->toBe('sparse@example.test')
        ->and($user->phone)->toBe('+60123334444')
        ->and($user->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($user->daily_prayer_institution_id)->toBe($dailyInstitution->id)
        ->and($user->friday_prayer_institution_id)->toBe($fridayInstitution->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();
});

it('lists, creates, and revokes member MCP tokens through account settings', function () {
    $institution = Institution::factory()->create([
        'name' => 'MCP Token Institution',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'name' => 'MCP Token User',
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $user, 'admin');

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.forms.account-settings'))
        ->assertOk()
        ->assertJsonPath('data.mcp_tokens_endpoint', route('api.client.account-settings.mcp-tokens.index'))
        ->assertJsonPath('data.mcp_token_store_endpoint', route('api.client.account-settings.mcp-tokens.store'))
        ->assertJsonPath('data.mcp_servers.member.endpoint', url('/mcp/member'));

    $this->getJson(route('api.client.account-settings.mcp-tokens.index'))
        ->assertOk()
        ->assertJsonPath('data.tokens', []);

    $createResponse = $this->postJson(route('api.client.account-settings.mcp-tokens.store'), [
        'name' => 'VS Code Member MCP',
        'server' => 'member',
    ])->assertCreated();

    $tokenId = $createResponse->json('data.token_meta.id');

    expect($createResponse->json('data.token'))->toBeString()
        ->and($createResponse->json('data.token_meta.server'))->toBe('member')
        ->and($user->fresh()->tokens()->value('abilities'))->toBe([McpTokenManager::MEMBER_ABILITY]);

    $this->getJson(route('api.client.account-settings.mcp-tokens.index'))
        ->assertOk()
        ->assertJsonPath('data.tokens.0.name', 'VS Code Member MCP')
        ->assertJsonPath('data.tokens.0.server', 'member')
        ->assertJsonPath('data.tokens.0.endpoint', url('/mcp/member'));

    $this->deleteJson(route('api.client.account-settings.mcp-tokens.destroy', ['tokenId' => $tokenId]))
        ->assertOk()
        ->assertJsonPath('message', 'MCP token revoked successfully.');

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('forbids MCP token management for authenticated users without MCP access', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.account-settings.mcp-tokens.index'))
        ->assertForbidden();

    $this->postJson(route('api.client.account-settings.mcp-tokens.store'), [
        'name' => 'No Access MCP',
        'server' => 'member',
    ])->assertForbidden();
});

it('lists legacy wildcard MCP tokens as admin tokens for admin-capable users', function () {
    if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'super_admin',
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $user->createToken('legacy-admin-mcp');

    Sanctum::actingAs($user);

    $this->getJson(route('api.client.account-settings.mcp-tokens.index'))
        ->assertOk()
        ->assertJsonPath('data.tokens.0.name', 'legacy-admin-mcp')
        ->assertJsonPath('data.tokens.0.server', 'admin')
        ->assertJsonPath('data.tokens.0.endpoint', url('/mcp/admin'));
});
