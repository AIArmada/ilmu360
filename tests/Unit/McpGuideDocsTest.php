<?php

declare(strict_types=1);

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

    $expected = [
        'donation-channels',
        'events',
        'inspirations',
        'institutions',
        'references',
        'reports',
        'series',
        'spaces',
        'speakers',
        'subdistricts',
        'tags',
        'venues',
    ];

    sort($expected);

    expect(documentedMcpGuideBulletList($markdown, 'Current structurally write-capable admin resources include:'))
        ->toEqual($expected)
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
        ->not->toContain('docs-member-mcp-guide')
        ->not->toContain('member-list-contribution-requests')
        ->not->toContain('member-update-record');
});

it('keeps the member MCP guide aligned with the live member write-capable resources', function (): void {
    $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_member_agent_guide.md') ?: '';

    $expected = [
        'events',
        'institutions',
        'references',
        'speakers',
    ];

    sort($expected);

    expect(documentedMcpGuideBulletList($markdown, 'Current member-write-capable resources include:'))
        ->toEqual($expected)
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
