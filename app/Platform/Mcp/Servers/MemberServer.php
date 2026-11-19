<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Servers;

use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberApproveContributionRequestTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberCancelContributionRequestTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberListContributionRequestsTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberRejectContributionRequestTool;
use App\Features\Events\EntryPoints\Mcp\Prompts\MemberEventCoverImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Prompts\MemberEventPosterImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberSearchEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberUploadEventCoverImageTool;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberUploadEventPosterImageTool;
use App\Features\GitHubIssues\EntryPoints\Mcp\Tools\MemberCreateGitHubIssueTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberCancelMembershipApplicationTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberListMembershipApplicationsTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberSubmitMembershipApplicationTool;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Prompts\MemberDocumentationToolRoutingPrompt;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Resources\MemberMcpGuideResource;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Tools\MemberDocumentationFetchTool;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Tools\MemberDocumentationSearchTool;
use App\Platform\Mcp\Methods\MemberCallToolWithDocumentationPreflight;
use App\Platform\Mcp\Methods\MemberReadResourceWithDocumentationPreflight;
use App\Platform\Mcp\Tools\Member\MemberGetRecordActionsTool;
use App\Platform\Mcp\Tools\Member\MemberGetRecordTool;
use App\Platform\Mcp\Tools\Member\MemberGetResourceMetaTool;
use App\Platform\Mcp\Tools\Member\MemberGetWriteSchemaTool;
use App\Platform\Mcp\Tools\Member\MemberListRecordsTool;
use App\Platform\Mcp\Tools\Member\MemberListRelatedRecordsTool;
use App\Platform\Mcp\Tools\Member\MemberListResourcesTool;
use App\Platform\Mcp\Tools\Member\MemberUpdateRecordTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('ilmu360-member')]
#[Version('1.0.0')]
#[Instructions('Authenticated member MCP server for Ahli-scoped institutions, people, references, and related events. Supports scoped resource discovery, record reads, record-specific next-step MCP actions, schema-guided updates for writable Ahli records when the authenticated member has the matching scoped permissions, plus member-side workflow tools for contribution-request queues and membership applications that mirror the Ahli dashboard. Event cover images are fixed at 16:9 and event poster images are fixed at 3:4. Media fields use JSON base64 file descriptors when advertised by the schema. To generate event images: use the `member-event-cover-image-prompt` or `member-event-poster-image-prompt` prompts to get the engineered prompt text and brand reference images, generate the image using ChatGPT native image generation, then upload the result with `member-upload-event-cover-image` or `member-upload-event-poster-image`. Entity-selection hint for record search: institution-type nouns (`masjid`, `surau`, `madrasah`, `maahad`, `pondok`, `sekolah`, `kolej`, `universiti`) should be searched as `institutions` first; venue-type nouns (`dewan`, `auditorium`, `stadium`, `perpustakaan`, `padang`, `hotel`) should be searched as `venues` first; `spaces` are finer-grained sublocations inside institutions and should not be the default first lookup for named mosques or surau. The server exposes the primary member MCP guide as a raw markdown resource, read-only `search` / `fetch` documentation tools that expose the broader verified member docs catalog for tool-centric clients such as ChatGPT and the OpenAI Responses MCP integration, plus a `documentation-tool-routing` prompt that explains when to use the guide and the documentation tools. Operational MCP tool calls are rejected until `docs-member-mcp-guide` has been fetched or the guide resource has been read in the current initialized MCP session. Designed for bearer-token clients such as VS Code, ChatGPT, Gemini, Grok, Claude, and Opencode.')]
class MemberServer extends Ilmu360Server
{
    #[\Override]
    public int $defaultPaginationLength = 50;

    #[\Override]
    protected function boot(): void
    {
        $this->addCapability(self::CAPABILITY_COMPLETIONS);
        $this->addMethod('tools/call', MemberCallToolWithDocumentationPreflight::class);
        $this->addMethod('resources/read', MemberReadResourceWithDocumentationPreflight::class);
    }

    #[\Override]
    protected array $resources = [
        MemberMcpGuideResource::class,
    ];

    #[\Override]
    protected array $prompts = [
        MemberDocumentationToolRoutingPrompt::class,
        MemberEventCoverImagePrompt::class,
        MemberEventPosterImagePrompt::class,
    ];

    #[\Override]
    protected array $tools = [
        MemberDocumentationSearchTool::class,
        MemberDocumentationFetchTool::class,
        MemberUploadEventCoverImageTool::class,
        MemberUploadEventPosterImageTool::class,
        MemberListResourcesTool::class,
        MemberGetResourceMetaTool::class,
        MemberSearchEventsTool::class,
        MemberListRecordsTool::class,
        MemberListRelatedRecordsTool::class,
        MemberGetRecordTool::class,
        MemberGetRecordActionsTool::class,
        MemberGetWriteSchemaTool::class,
        MemberUpdateRecordTool::class,
        MemberListContributionRequestsTool::class,
        MemberApproveContributionRequestTool::class,
        MemberRejectContributionRequestTool::class,
        MemberCancelContributionRequestTool::class,
        MemberListMembershipApplicationsTool::class,
        MemberSubmitMembershipApplicationTool::class,
        MemberCancelMembershipApplicationTool::class,
        MemberCreateGitHubIssueTool::class,
    ];
}
