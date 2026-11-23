<?php

declare(strict_types=1);

namespace App\Features\Mcp\EntryPoints\Mcp\Resources;

use App\Features\Mcp\Services\MemberMcpDocumentationPreflight;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Annotations\Audience;
use Laravel\Mcp\Server\Annotations\Priority;

#[Audience([Role::User, Role::Assistant])]
#[Priority(1.0)]
class MemberMcpGuideResource extends MarkdownDocumentResource
{
    #[\Override]
    protected string $name = 'docs-member-mcp-guide';

    #[\Override]
    protected string $title = 'ilmu360° Member MCP Agent Guide';

    #[\Override]
    protected string $description = 'Verified markdown guide for member MCP agent consumption: auth, transport, discovery primitives, capability matrix, writable resources, and workflow guidance.';

    #[\Override]
    protected string $uri = 'file://docs/ilmu360_mcp_member_agent_guide.md';

    public function __construct(private readonly MemberMcpDocumentationPreflight $preflight) {}

    #[\Override]
    protected function markGuideRead(Request $request): void
    {
        $this->preflight->markGuideInContext($request);
    }

    protected function documentRelativePath(): string
    {
        return 'docs/ilmu360_mcp_member_agent_guide.md';
    }
}
