<?php

declare(strict_types=1);

use App\Support\Api\Admin\AdminResourceMutationService;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Api\Member\MemberResourceMutationService;
use App\Support\Api\Member\MemberResourceRegistry;
use Tests\TestCase;

pest()->extend(TestCase::class);

function liveWritableAdminResourceKeys(): array
{
    $registry = app(AdminResourceRegistry::class);
    $mutations = app(AdminResourceMutationService::class);

    $keys = [];

    foreach ($registry->resources() as $resourceClass) {
        if ($mutations->supports($resourceClass)) {
            $keys[] = $registry->keyFor($resourceClass);
        }
    }

    sort($keys);

    return $keys;
}

function liveWritableMemberResourceKeys(): array
{
    $registry = app(MemberResourceRegistry::class);
    $mutations = app(MemberResourceMutationService::class);

    $keys = [];

    foreach ($registry->resources() as $resourceClass) {
        if ($mutations->supports($resourceClass)) {
            $keys[] = $registry->keyFor($resourceClass);
        }
    }

    sort($keys);

    return $keys;
}

function documentedMcpGuideBulletList(string $markdown, string $heading): array
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $capturing = false;
    $items = [];

    foreach ($lines as $line) {
        if ($capturing && str_starts_with(trim($line), '## ')) {
            break;
        }

        if (trim($line) === '## '.$heading) {
            $capturing = true;

            continue;
        }

        if ($capturing && preg_match('/^- `([^`]+)`$/', trim($line), $matches) === 1) {
            $items[] = $matches[1];
        }
    }

    sort($items);

    return $items;
}

it('keeps the admin MCP guide aligned with the live admin write-capable resources', function (): void {
    $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_admin_agent_guide.md') ?: '';

    expect(documentedMcpGuideBulletList($markdown, 'Current structurally write-capable admin resources include:'))
        ->toEqual(liveWritableAdminResourceKeys())
        ->and($markdown)
        ->toContain('# ilmu360° Admin MCP Agent Guide')
        ->toContain('docs-admin-mcp-guide')
        ->toContain('file://docs/ilmu360_mcp_admin_agent_guide.md')
        ->toContain('## MCP capability matrix')
        ->toContain('## Writable resource matrix')
        ->toContain('## Entity selection heuristics for record search')
        ->toContain('## Quick search playbook')
        ->toContain('## Validate-only preview behavior')
        ->toContain('`apply_defaults=true` is preview-only')
        ->toContain('omitted or `null` preserves the current relationship set; `[]` detaches all')
        ->toContain('`person_keys` and `reference_keys` have presence-sensitive full-sync semantics')
        ->not->toContain('speaker_keys')
        ->not->toContain('docs-member-mcp-guide')
        ->not->toContain('member-list-contribution-requests')
        ->not->toContain('member-update-record');
});

it('keeps the member MCP guide aligned with the live member write-capable resources', function (): void {
    $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_member_agent_guide.md') ?: '';

    expect(documentedMcpGuideBulletList($markdown, 'Current member-write-capable resources include:'))
        ->toEqual(liveWritableMemberResourceKeys())
        ->and($markdown)
        ->toContain('# ilmu360° Member MCP Agent Guide')
        ->toContain('docs-member-mcp-guide')
        ->toContain('file://docs/ilmu360_mcp_member_agent_guide.md')
        ->toContain('## MCP capability matrix')
        ->toContain('## Writable resource matrix')
        ->toContain('## Entity selection heuristics for record search')
        ->toContain('## Quick search playbook')
        ->toContain('## Validate-only preview behavior')
        ->toContain('member-list-related-records')
        ->toContain('member-update-record')
        ->not->toContain('docs-admin-mcp-guide')
        ->not->toContain('admin-moderate-event')
        ->not->toContain('admin-triage-report');
});

it('documents the canonical MCP membership and file descriptor contracts', function (): void {
    $documents = collect([
        'docs/ilmu360_mcp_guide.md',
        'docs/ilmu360_mcp_admin_agent_guide.md',
        'docs/ilmu360_mcp_member_agent_guide.md',
        'docs/ilmu360_mcp_tool_examples.md',
        'docs/CHATGPT_FILE_PARAMS_INTEGRATION.md',
    ])->map(fn (string $path): string => file_get_contents(dirname(__DIR__, 2).'/'.$path) ?: '')->implode("\n");

    expect($documents)
        ->toContain('member-submit-membership-application')
        ->toContain('admin-review-membership-application')
        ->toContain('json_file_descriptor')
        ->toContain('exactly_one_of')
        ->not->toContain('membership-claim')
        ->not->toContain('member-submit-membership-claim')
        ->not->toContain('json_base64_descriptor')
        ->not->toContain('Accepted aliases')
        ->not->toContain('contentBase64')
        ->not->toContain('downloadUrl');
});
