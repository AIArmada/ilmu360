<?php

use App\Actions\Membership\AddMemberToSubject;
use App\Models\Institution;
use App\Models\User;
use App\Support\Mcp\McpTokenManager;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

it('issues a bearer token for an admin-capable user', function () {
    $user = tokenCommandUser('super_admin');

    $this->artisan('mcp:token', [
        'email' => $user->email,
        'name' => 'copilot-mcp',
    ])->expectsOutputToContain('Bearer ')
        ->assertSuccessful();

    expect($user->fresh()->tokens()->value('abilities'))->toBe([McpTokenManager::ADMIN_ABILITY]);
});

it('issues a bearer token for a member-capable user when the member server is requested', function () {
    $user = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    app(AddMemberToSubject::class)->handle($institution, $user, 'admin');

    $this->artisan('mcp:token', [
        'email' => $user->email,
        'name' => 'copilot-member-mcp',
        '--server' => 'member',
    ])->expectsOutputToContain('Bearer ')
        ->assertSuccessful();

    expect($user->fresh()->tokens()->value('abilities'))->toBe([McpTokenManager::MEMBER_ABILITY]);
});

it('rejects users without application admin access', function () {
    $user = User::factory()->create();

    $this->artisan('mcp:token', [
        'email' => $user->email,
        'name' => 'copilot-mcp',
    ])->expectsOutputToContain('does not have application admin access')
        ->assertFailed();
});

it('rejects member token issuance for users without member MCP access', function () {
    $user = User::factory()->create();

    $this->artisan('mcp:token', [
        'email' => $user->email,
        'name' => 'copilot-member-mcp',
        '--server' => 'member',
    ])->expectsOutputToContain('does not have member MCP access')
        ->assertFailed();
});

function tokenCommandUser(string $role): User
{
    if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}
