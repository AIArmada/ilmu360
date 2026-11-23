<?php

declare(strict_types=1);

use AIArmada\Authz\Models\Role;
use App\Features\Institutions\Models\Institution;
use App\Features\Mcp\EntryPoints\Mcp\Tools\AdminDocumentationFetchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\AdminDocumentationSearchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\MemberDocumentationFetchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\MemberDocumentationSearchTool;
use App\Models\User;
use App\Platform\Documentation\DocumentationLibrary;
use App\Platform\Mcp\McpTokenManager;
use App\Platform\Mcp\Servers\AdminServer;
use App\Platform\Mcp\Servers\MemberServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\ProtocolVersion;

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

it('documents every audience-scoped MCP documentation catalog id', function (): void {
    $guides = [
        DocumentationLibrary::AUDIENCE_MCP_ADMIN => file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_admin_agent_guide.md') ?: '',
        DocumentationLibrary::AUDIENCE_MCP_MEMBER => file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_member_agent_guide.md') ?: '',
    ];

    foreach ($guides as $audience => $markdown) {
        $catalogIds = collect(app(DocumentationLibrary::class)->forAudience($audience))
            ->pluck('id')
            ->all();

        expect($markdown)->toContain(...array_map(static fn (string $id): string => '`'.$id.'`', $catalogIds));
    }
});

it('discovers the current MCP protocol and server branding on both authenticated endpoints', function (string $audience): void {
    $user = $audience === 'admin' ? documentationMcpAdminUser() : documentationMcpMemberUser();
    $ability = $audience === 'admin' ? McpTokenManager::ADMIN_ABILITY : McpTokenManager::MEMBER_ABILITY;
    $version = ProtocolVersion::LATEST->value;

    $response = $this->withToken($user->createToken('mcp-discovery-test', [$ability])->plainTextToken)
        ->postJson('/mcp/'.$audience, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => [
                '_meta' => [
                    MetaKey::PROTOCOL_VERSION->value => $version,
                    MetaKey::CLIENT_CAPABILITIES->value => (object) [],
                ],
            ],
        ], [
            'Mcp-Protocol-Version' => $version,
            'Mcp-Method' => 'server/discover',
        ])
        ->assertOk()
        ->assertJsonPath('result.supportedVersions.0', $version)
        ->assertJsonPath('result.resultType', 'complete');

    $serverInfo = $response->json('result._meta')[MetaKey::SERVER_INFO->value];
    expect($serverInfo['name'])->toBe('ilmu360-'.$audience)
        ->and($serverInfo['icons'][0]['mimeType'])->toBe('image/png')
        ->and($serverInfo['icons'][0]['src'])->toEndWith('/images/favicon-96x96.png');
})->with(['admin', 'member']);

it('reads and completes verified documentation URLs within the allowed MCP audience', function (string $audience): void {
    $user = $audience === 'admin' ? documentationMcpAdminUser() : documentationMcpMemberUser();
    $ability = $audience === 'admin' ? McpTokenManager::ADMIN_ABILITY : McpTokenManager::MEMBER_ABILITY;
    $otherAudience = $audience === 'admin' ? 'member' : 'admin';
    $this->withToken($user->createToken('mcp-documentation-template-test', [$ability])->plainTextToken);

    $rpc = fn (string $method, array $params = []) => $this->postJson('/mcp/'.$audience, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);

    $template = 'file://docs/{filename}';
    $rpc('resources/templates/list')
        ->assertOk()
        ->assertJsonPath('result.resourceTemplates.0.uriTemplate', $template)
        ->assertJsonPath('result.resourceTemplates.0.mimeType', 'text/markdown');

    $rpc('completion/complete', [
        'ref' => ['type' => 'ref/resource', 'uri' => $template],
        'argument' => ['name' => 'filename', 'value' => 'ilmu360_mcp_'.$audience.'_'],
    ])->assertOk()->assertJsonFragment(['values' => ['ilmu360_mcp_'.$audience.'_agent_guide.md']]);

    $rpc('completion/complete', [
        'ref' => ['type' => 'ref/resource', 'uri' => $template],
        'argument' => ['name' => 'filename', 'value' => 'ilmu360_mcp_'.$otherAudience.'_'],
    ])->assertOk()->assertJsonPath('result.completion.values', []);

    $uri = 'file://docs/ilmu360_mcp_tool_examples.md';
    $rpc('resources/read', ['uri' => $uri])
        ->assertOk()
        ->assertJsonPath('result.contents.0.uri', $uri)
        ->assertJsonPath('result.contents.0.mimeType', 'text/markdown')
        ->assertJsonPath('result.contents.0.text', fn (string $text): bool => str_contains($text, '### Discover resources'));

    $rpc('resources/read', ['uri' => 'file://docs/ilmu360_mcp_'.$otherAudience.'_agent_guide.md'])
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32602);
    $rpc('resources/read', ['uri' => 'file://docs/%2e%2e%2f.env'])
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32602);
    $rpc('resources/read', ['uri' => ['invalid']])
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32602);
    $rpc('tools/call', ['name' => ['invalid']])
        ->assertBadRequest()
        ->assertJsonPath('error.code', -32602);

    $rpc('resources/read', ['uri' => 'file://docs/ilmu360_mcp_'.$audience.'_agent_guide.md'])
        ->assertOk();
    $rpc('tools/call', ['name' => $audience.'-list-resources'])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonStructure(['result' => ['structuredContent' => ['data' => ['resources']]]]);
})->with(['admin', 'member']);

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

    addTestMember($institution, $member, $role);

    return $member;
}
