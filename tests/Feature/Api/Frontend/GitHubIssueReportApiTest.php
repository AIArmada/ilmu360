<?php

use AIArmada\CommerceSupport\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    configureGitHubIssueReporting();
    Http::preventStrayRequests();
});

it('exposes the github issue report flow in the manifest when enabled', function () {
    $flow = $this->getJson(route('api.client.manifest'))
        ->assertOk()
        ->json('data.flows.github_issue_report');

    expect($flow)->toMatchArray([
        'method' => 'POST',
        'endpoint' => route('api.client.github-issues.store'),
        'schema_endpoint' => route('api.client.forms.github-issue-report'),
        'auth_required' => true,
    ]);
});

it('returns the github issue report form contract', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.client.forms.github-issue-report'))
        ->assertOk();

    expect($response->json('data.flow'))->toBe('github_issue_report')
        ->and($response->json('data.endpoint'))->toBe(route('api.client.github-issues.store'))
        ->and(collect($response->json('data.fields'))->pluck('name')->all())
        ->toContain('category', 'title', 'summary', 'platform', 'tool_name', 'proposal')
        ->and(data_get(collect($response->json('data.fields'))->firstWhere('name', 'category'), 'allowed_values'))
        ->toContain('bug', 'docs_mismatch', 'proposal', 'feature_request', 'parameter_change', 'other');
});

it('creates a plain github issue for non-admin api reporters', function () {
    $user = User::factory()->create([
        'name' => 'API Reporter',
    ]);

    Sanctum::actingAs($user);

    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::response([
            'number' => 123,
            'title' => '[Bug] Fix MCP connector issue creation',
            'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/123',
            'html_url' => 'https://github.com/AIArmada/ilmu360/issues/123',
        ], 201),
    ]);

    $response = $this->postJson(route('api.client.github-issues.store'), githubIssuePayload())
        ->assertCreated();

    expect($response->json('data.issue.assigned_to_copilot'))->toBeFalse()
        ->and($response->json('data.issue.copilot_model'))->toBeNull()
        ->and($response->json('data.issue.repository'))->toBe('AIArmada/ilmu360')
        ->and($response->json('meta.request_id'))->toBeString();

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->method() === 'POST'
            && (string) $request->url() === 'https://api.github.com/repos/AIArmada/ilmu360/issues'
            && data_get($payload, 'title') === '[Bug] Fix MCP connector issue creation'
            && data_get($payload, 'assignees') === null
            && data_get($payload, 'agent_assignment') === null;
    });
});

it('assigns copilot for admin api reporters and falls back configured models', function () {
    $admin = apiGithubIssueAdminUser();

    Sanctum::actingAs($admin);

    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::sequence()
            ->push(['message' => 'Validation Failed', 'errors' => [['field' => 'agent_assignment.model', 'message' => 'Unsupported model']]], 422)
            ->push([
                'number' => 456,
                'title' => '[Bug] Fix MCP connector issue creation',
                'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/456',
                'html_url' => 'https://github.com/AIArmada/ilmu360/issues/456',
            ], 201),
    ]);

    $response = $this->postJson(route('api.client.github-issues.store'), githubIssuePayload())
        ->assertCreated();

    expect($response->json('data.issue.assigned_to_copilot'))->toBeTrue()
        ->and($response->json('data.issue.copilot_model'))->toBe('GPT-5.2-Codex')
        ->and($response->json('data.issue.attempted_models'))->toBe(['GPT-5.4', 'GPT-5.2-Codex']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'agent_assignment.model') === 'GPT-5.4');
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'agent_assignment.model') === 'GPT-5.2-Codex');
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'assignees.0') === 'copilot-swe-agent[bot]');
});

it('creates a plain github issue for admin api reporters when copilot assignment is disabled', function () {
    $admin = apiGithubIssueAdminUser();

    Sanctum::actingAs($admin);

    configureGitHubIssueReporting([
        'admin_copilot_assignment_enabled' => false,
    ]);

    Http::fake([
        'https://api.github.com/repos/AIArmada/ilmu360/issues' => Http::response([
            'number' => 457,
            'title' => '[Bug] Fix MCP connector issue creation',
            'url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/457',
            'html_url' => 'https://github.com/AIArmada/ilmu360/issues/457',
        ], 201),
    ]);

    $response = $this->postJson(route('api.client.github-issues.store'), githubIssuePayload())
        ->assertCreated();

    expect($response->json('data.issue.assigned_to_copilot'))->toBeFalse()
        ->and($response->json('data.issue.copilot_model'))->toBeNull()
        ->and($response->json('data.issue.attempted_models'))->toBe([]);

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->method() === 'POST'
            && (string) $request->url() === 'https://api.github.com/repos/AIArmada/ilmu360/issues'
            && data_get($payload, 'assignees') === null
            && data_get($payload, 'agent_assignment') === null;
    });
});

function configureGitHubIssueReporting(array $overrides = []): void
{
    config()->set('services.github.issues', array_replace([
        'enabled' => true,
        'token' => 'github-token',
        'api_base' => 'https://api.github.com',
        'api_version' => '2026-03-10',
        'repository_owner' => 'AIArmada',
        'repository_name' => 'ilmu360',
        'base_branch' => 'main',
        'custom_agent' => null,
        'custom_instructions' => 'Use repository tests and conventions when following up.',
        'admin_model' => 'GPT-5.4',
        'admin_model_fallbacks' => ['GPT-5.2-Codex', 'Auto'],
        'admin_copilot_assignment_enabled' => true,
        'copilot_assignee' => 'copilot-swe-agent[bot]',
    ], $overrides));
}

function apiGithubIssueAdminUser(): User
{
    if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) fake()->uuid(),
            'name' => 'super_admin',
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

/**
 * @return array<string, string>
 */
function githubIssuePayload(): array
{
    return [
        'category' => 'bug',
        'title' => 'Fix MCP connector issue creation',
        'summary' => 'Creating an issue from the connector should preserve the current runtime context and user metadata.',
        'platform' => 'chatgpt',
        'client_name' => 'ChatGPT',
        'client_version' => 'GPT-5.4',
        'current_endpoint' => '/mcp/member',
        'tool_name' => 'member-create-github-issue',
        'expected_behavior' => 'The issue should be created with the correct context and assignment rules.',
        'actual_behavior' => 'The shared workflow does not exist yet.',
        'proposal' => 'Add a shared API and MCP GitHub issue reporting workflow.',
    ];
}
