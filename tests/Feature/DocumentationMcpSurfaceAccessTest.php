<?php

declare(strict_types=1);

use App\Actions\Membership\AddMemberToSubject;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Admin\AdminDocumentationFetchTool;
use App\Mcp\Tools\Admin\AdminDocumentationSearchTool;
use App\Mcp\Tools\Member\MemberDocumentationFetchTool;
use App\Mcp\Tools\Member\MemberDocumentationSearchTool;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

it('lets admin MCP documentation tools search and fetch broader verified docs', function (): void {
    $admin = documentationMcpAdminUser();

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationSearchTool::class, [
            'query' => 'tool examples',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-mcp-tool-examples'
                    && ($result['title'] ?? null) === 'ilmu360° MCP Tool Examples'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_tool_examples.md'
            ))
            ->etc())
        ->assertSee([
            'docs-mcp-tool-examples',
            'ilmu360° MCP Tool Examples',
        ]);

    AdminServer::actingAs($admin)
        ->tool(AdminDocumentationFetchTool::class, [
            'id' => 'docs-technical-documentation',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-technical-documentation')
            ->where('title', 'ilmu360° Developer Technical Documentation')
            ->where('url', 'file://docs/ilmu360_technical_documentation.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_technical_documentation.md')
            ->where('metadata.relative_path', 'docs/ilmu360_technical_documentation.md')
            ->where('text', fn (string $text): bool => str_contains($text, '## 1. System Overview'))
            ->etc())
        ->assertSee([
            'docs-technical-documentation',
            '# ilmu360° Developer Technical Documentation',
            '## 1. System Overview',
        ]);
});

it('lets member MCP documentation tools search and fetch broader verified docs', function (): void {
    $member = documentationMcpMemberUser();

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationSearchTool::class, [
            'query' => 'tool examples',
        ])
        ->assertOk()
        ->assertName('search')
        ->assertTitle('Search Verified Documentation')
        ->assertStructuredContent(fn ($json) => $json
            ->has('results')
            ->where('results', fn ($results): bool => collect($results)->contains(
                fn (array $result): bool => ($result['id'] ?? null) === 'docs-mcp-tool-examples'
                    && ($result['title'] ?? null) === 'ilmu360° MCP Tool Examples'
                    && ($result['url'] ?? null) === 'file://docs/ilmu360_mcp_tool_examples.md'
            ))
            ->etc())
        ->assertSee([
            'docs-mcp-tool-examples',
            'ilmu360° MCP Tool Examples',
        ]);

    MemberServer::actingAs($member)
        ->tool(MemberDocumentationFetchTool::class, [
            'id' => 'docs-mcp-tool-examples',
        ])
        ->assertOk()
        ->assertName('fetch')
        ->assertTitle('Fetch Verified Documentation Page')
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', 'docs-mcp-tool-examples')
            ->where('title', 'ilmu360° MCP Tool Examples')
            ->where('url', 'file://docs/ilmu360_mcp_tool_examples.md')
            ->where('metadata.mime_type', 'text/markdown')
            ->where('metadata.resource_uri', 'file://docs/ilmu360_mcp_tool_examples.md')
            ->where('metadata.relative_path', 'docs/ilmu360_mcp_tool_examples.md')
            ->where('text', fn (string $text): bool => str_contains($text, '### Discover resources'))
            ->etc())
        ->assertSee([
            'docs-mcp-tool-examples',
            '# ilmu360° MCP Tool Examples',
            '### Discover resources',
        ]);
});

function documentationMcpAdminUser(string $role = 'super_admin'): User
{
    $roleRecord = Role::query()->where('name', $role)->where('guard_name', 'web')->first();

    if (! $roleRecord instanceof Role) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();

    $modelHasRolesTable = (string) (config('permission.table_names.model_has_roles') ?? 'model_has_roles');
    $rolePivotKey = (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
    $modelMorphKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');

    $assignment = [
        $rolePivotKey => $roleRecord->getKey(),
        $modelMorphKey => (string) $user->getKey(),
        'model_type' => $user->getMorphClass(),
    ];

    if (config('permission.teams')) {
        $teamForeignKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');
        $assignment[$teamForeignKey] = null;
    }

    DB::table($modelHasRolesTable)->insert($assignment);

    return $user;
}

function documentationMcpMemberUser(string $role = 'admin'): User
{
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $member = User::factory()->create();

    app(AddMemberToSubject::class)->handle($institution, $member, $role);

    return $member;
}