<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Docs;

use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Server\Annotations\Audience;
use Laravel\Mcp\Server\Annotations\Priority;

#[Audience([Role::User, Role::Assistant])]
#[Priority(1.0)]
class McpGuideResource extends MarkdownDocumentResource
{
    protected string $name = 'docs-admin-mcp-guide';

    protected string $title = 'ilmu360° Admin MCP Agent Guide';

    protected string $description = 'Verified markdown guide for admin MCP agent consumption: auth, transport, discovery primitives, capability matrix, writable resources, and workflow guidance.';

    protected string $uri = 'file://docs/ilmu360_mcp_admin_agent_guide.md';

    protected function documentRelativePath(): string
    {
        return 'docs/ilmu360_mcp_admin_agent_guide.md';
    }
}
