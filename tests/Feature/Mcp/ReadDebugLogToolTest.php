<?php

use App\Actions\Membership\AddMemberToSubject;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Admin\AdminReadDebugLogTool;
use App\Mcp\Tools\Member\MemberReadDebugLogTool;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Return the process-specific log path so parallel workers don't share a log file.
 */
function debugLogTestPath(): string
{
    $token = $_SERVER['TEST_TOKEN'] ?? null;
    $suffix = $token ? "-test-{$token}" : '-test';

    return storage_path("logs/laravel{$suffix}.log");
}

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Configure Laravel's single channel to write to a worker-specific log file
    // so parallel processes don't contaminate each other's log assertions.
    $logPath = debugLogTestPath();
    config()->set('logging.channels.single.path', $logPath);

    @unlink($logPath);
});

afterEach(function (): void {
    @unlink(debugLogTestPath());
});

it('returns mcp.image_upload log lines for admins', function (): void {
    $admin = debugLogAdminUser();

    $logPath = debugLogTestPath();

    file_put_contents($logPath, implode(PHP_EOL, [
        '[2026-05-02 12:00:00] local.DEBUG: mcp.image_upload: start {"event":"test-event"}',
        '[2026-05-02 12:00:01] local.DEBUG: Some other log entry',
        '[2026-05-02 12:00:02] local.DEBUG: mcp.image_upload: complete {"media_id":"abc-123"}',
    ]).PHP_EOL);

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'mcp.image_upload'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'mcp.image_upload')
            ->where('all', false)
            ->where('total_matched', 2)
            ->where('lines.0', '[2026-05-02 12:00:00] local.DEBUG: mcp.image_upload: start {"event":"test-event"}')
            ->where('lines.1', '[2026-05-02 12:00:02] local.DEBUG: mcp.image_upload: complete {"media_id":"abc-123"}')
            ->etc());
});

it('respects the lines limit', function (): void {
    $admin = debugLogAdminUser();

    $logPath = debugLogTestPath();
    $allLines = array_map(
        fn (int $i): string => "[2026-05-02 12:00:{$i}] local.DEBUG: mcp.image_upload: entry {$i}",
        range(0, 9),
    );
    file_put_contents($logPath, implode(PHP_EOL, $allLines).PHP_EOL);

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'mcp.image_upload', 'lines' => 3])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'mcp.image_upload')
            ->where('all', false)
            ->where('total_matched', 10)
            ->count('lines', 3)
            ->etc());
});

it('returns all matching lines when all is true', function (): void {
    $admin = debugLogAdminUser();

    $logPath = debugLogTestPath();
    $allLines = array_map(
        fn (int $i): string => "[2026-05-02 12:00:{$i}] local.DEBUG: mcp.image_upload: entry {$i}",
        range(0, 9),
    );
    file_put_contents($logPath, implode(PHP_EOL, $allLines).PHP_EOL);

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'mcp.image_upload', 'all' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'mcp.image_upload')
            ->where('all', true)
            ->where('total_matched', 10)
            ->count('lines', 10)
            ->etc());
});

it('returns an empty result when no log file exists', function (): void {
    $admin = debugLogAdminUser();

    @unlink(debugLogTestPath());

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'mcp.image_upload'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('lines', [])
            ->where('total_matched', 0)
            ->where('filter', 'mcp.image_upload')
            ->where('all', false)
            ->etc());
});

it('accepts a custom filter string', function (): void {
    $admin = debugLogAdminUser();

    $logPath = debugLogTestPath();
    file_put_contents($logPath, implode(PHP_EOL, [
        '[2026-05-02 12:00:00] local.DEBUG: mcp.image_upload: start',
        '[2026-05-02 12:00:01] local.DEBUG: custom.channel: my entry',
    ]).PHP_EOL);

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'custom.channel'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'custom.channel')
            ->where('all', false)
            ->where('total_matched', 1)
            ->etc());
});

it('returns mcp.image_upload log lines for members', function (): void {
    [$member] = debugLogMemberContext();

    $logPath = debugLogTestPath();
    file_put_contents($logPath, implode(PHP_EOL, [
        '[2026-05-02 12:00:00] local.DEBUG: mcp.image_upload: start {"event":"member-event"}',
        '[2026-05-02 12:00:01] local.DEBUG: Some other entry',
    ]).PHP_EOL);

    MemberServer::actingAs($member)
        ->tool(MemberReadDebugLogTool::class, ['filter' => 'mcp.image_upload'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'mcp.image_upload')
            ->where('all', false)
            ->where('total_matched', 1)
            ->etc());
});

it('reads mcp tool execution logs by default for admins', function (): void {
    $admin = debugLogAdminUser();

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['filter' => 'custom.channel'])
        ->assertOk();

    AdminServer::actingAs($admin)
        ->tool(AdminReadDebugLogTool::class, ['all' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('filter', 'mcp.tool_execution')
            ->where('all', true)
            ->where('total_matched', fn (int $value): bool => $value >= 2)
            ->where('lines.0', fn (string $line): bool => str_contains($line, 'mcp.tool_execution'))
            ->etc());
});

it('tool is registered and marked read-only on both servers', function (): void {
    $adminTool = app(AdminReadDebugLogTool::class)->toArray();
    $memberTool = app(MemberReadDebugLogTool::class)->toArray();

    expect($adminTool['annotations'] ?? [])->toMatchArray(['readOnlyHint' => true])
        ->and($memberTool['annotations'] ?? [])->toMatchArray(['readOnlyHint' => true]);
});

function debugLogAdminUser(): User
{
    $role = 'super_admin';

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

/**
 * @return array{0: User}
 */
function debugLogMemberContext(): array
{
    $member = User::factory()->create([
        'phone' => '+60113334455',
        'phone_verified_at' => now(),
    ]);

    // Assign an institution membership so hasMemberMcpAccess() is satisfied
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);
    app(AddMemberToSubject::class)->handle($institution, $member, 'admin');

    return [$member];
}
