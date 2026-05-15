<?php

declare(strict_types=1);

it('documents the app MCP usage guide', function (): void {
    $guide = file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360_mcp_guide.md') ?: '';

    expect($guide)
        ->toContain('/mcp/admin')
        ->toContain('/mcp/member')
        ->toContain('php artisan mcp:token someone@example.com "VS Code Admin MCP" --server=admin')
        ->toContain('php artisan mcp:token someone@example.com "VS Code Member MCP" --server=member')
        ->toContain('php artisan mcp:inspector ilmu360-admin-local')
        ->toContain('php artisan mcp:inspector ilmu360-member-local')
        ->toContain('MCP_REDIRECT_DOMAINS')
        ->toContain('MCP_CUSTOM_SCHEMES')
        ->toContain('docs/ilmu360_mcp_admin_agent_guide.md')
        ->toContain('docs/ilmu360_mcp_member_agent_guide.md')
        ->toContain('docs/ilmu360_mcp_event_csv_json_creation_guide.md')
        ->toContain('docs-admin-mcp-guide')
        ->toContain('docs-admin-event-csv-json-create-guide')
        ->toContain('docs-member-mcp-guide')
        ->not->toContain('docs-mcp-guide');
});
