<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Servers;

use App\Features\Contributions\EntryPoints\Mcp\Tools\AdminGetContributionRequestReviewSchemaTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\AdminReviewContributionRequestTool;
use App\Features\Events\EntryPoints\Mcp\Prompts\AdminEventCoverImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Prompts\AdminEventPosterImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminBatchCreateEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminBatchUpdateEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminCreateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminGetEventModerationSchemaTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminModerateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminSearchEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUpdateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUploadEventCoverImageTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUploadEventPosterImageTool;
use App\Features\GitHubIssues\EntryPoints\Mcp\Tools\AdminCreateGitHubIssueTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\AdminGetMembershipApplicationReviewSchemaTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\AdminReviewMembershipApplicationTool;
use App\Features\Reports\EntryPoints\Mcp\Tools\AdminGetReportTriageSchemaTool;
use App\Features\Reports\EntryPoints\Mcp\Tools\AdminTriageReportTool;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Prompts\DocumentationToolRoutingPrompt;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Resources\McpGuideResource;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Tools\AdminDocumentationFetchTool;
use App\Platform\ApiDocumentation\EntryPoints\Mcp\Tools\AdminDocumentationSearchTool;
use App\Platform\Diagnostics\EntryPoints\Mcp\Tools\AdminReadDebugLogTool;
use App\Platform\Mcp\Methods\CallToolWithDocumentationPreflight;
use App\Platform\Mcp\Methods\ReadResourceWithDocumentationPreflight;
use App\Platform\Mcp\Tools\Admin\AdminBatchCreateRecordsTool;
use App\Platform\Mcp\Tools\Admin\AdminBatchUpdateRecordsTool;
use App\Platform\Mcp\Tools\Admin\AdminCreateRecordTool;
use App\Platform\Mcp\Tools\Admin\AdminGetRecordActionsTool;
use App\Platform\Mcp\Tools\Admin\AdminGetRecordMediaTool;
use App\Platform\Mcp\Tools\Admin\AdminGetRecordTool;
use App\Platform\Mcp\Tools\Admin\AdminGetResourceMetaTool;
use App\Platform\Mcp\Tools\Admin\AdminGetWriteSchemaTool;
use App\Platform\Mcp\Tools\Admin\AdminListRecordsTool;
use App\Platform\Mcp\Tools\Admin\AdminListRelatedRecordsTool;
use App\Platform\Mcp\Tools\Admin\AdminListResourcesTool;
use App\Platform\Mcp\Tools\Admin\AdminUpdateRecordTool;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('ilmu360-admin')]
#[Version('1.0.0')]
#[Instructions('Authenticated admin MCP server with parity to the Filament admin resource API for listing resources, reading records, traversing relations, getting record-specific next-step MCP actions, discovering MCP write schemas, getting explicit workflow schema descriptors for event moderation, report triage, contribution-request review, and membership-application review, writing supported fields for donation-channel, event, inspiration, institution, report, person, venue, reference, address-area, series, and space records. Reward resources are read-only through the generic admin API and MCP; any future reward mutation must call an AIArmada\\Rewards package Action through the Rewards feature. No reward-specific MCP tools are exposed. The server also runs explicit workflow actions for those admin review surfaces. Event cover images are fixed at 16:9 and event poster images are fixed at 3:4. Media fields use JSON base64 file descriptors when advertised by the schema. To generate event images: use the `admin-event-cover-image-prompt` or `admin-event-poster-image-prompt` prompts to get the engineered prompt text and brand reference images, generate the image using ChatGPT native image generation, then upload the result with `admin-upload-event-cover-image` or `admin-upload-event-poster-image`. Entity-selection hint for record search: institution-type nouns (`masjid`, `surau`, `madrasah`, `maahad`, `pondok`, `sekolah`, `kolej`, `universiti`) should be searched as `institutions` first; venue-type nouns (`dewan`, `auditorium`, `stadium`, `perpustakaan`, `padang`, `hotel`) should be searched as `venues` first; `spaces` are finer-grained sublocations inside institutions and should not be the default first lookup for named mosques or surau. The server exposes the primary admin MCP guide as a raw markdown resource, read-only `search` / `fetch` documentation tools that expose the broader verified admin docs catalog for tool-centric clients such as ChatGPT and the OpenAI Responses MCP integration, plus a `documentation-tool-routing` prompt that explains when to use the guide and the documentation tools. Operational MCP tool calls are rejected until `docs-admin-mcp-guide` has been fetched or the guide resource has been read in the current initialized MCP session.')]
class AdminServer extends Ilmu360Server
{
    #[\Override]
    public int $defaultPaginationLength = 50;

    #[\Override]
    protected function boot(): void
    {
        $this->addCapability(self::CAPABILITY_COMPLETIONS);
        $this->addMethod('tools/call', CallToolWithDocumentationPreflight::class);
        $this->addMethod('resources/read', ReadResourceWithDocumentationPreflight::class);
    }

    #[\Override]
    protected array $resources = [
        McpGuideResource::class,
    ];

    #[\Override]
    protected array $prompts = [
        DocumentationToolRoutingPrompt::class,
        AdminEventCoverImagePrompt::class,
        AdminEventPosterImagePrompt::class,
    ];

    #[\Override]
    protected array $tools = [
        AdminDocumentationSearchTool::class,
        AdminDocumentationFetchTool::class,
        AdminReadDebugLogTool::class,
        AdminUploadEventCoverImageTool::class,
        AdminUploadEventPosterImageTool::class,
        AdminListResourcesTool::class,
        AdminGetResourceMetaTool::class,
        AdminSearchEventsTool::class,
        AdminListRecordsTool::class,
        AdminListRelatedRecordsTool::class,
        AdminGetRecordTool::class,
        AdminGetRecordMediaTool::class,
        AdminGetRecordActionsTool::class,
        AdminGetWriteSchemaTool::class,
        AdminGetEventModerationSchemaTool::class,
        AdminGetReportTriageSchemaTool::class,
        AdminGetContributionRequestReviewSchemaTool::class,
        AdminGetMembershipApplicationReviewSchemaTool::class,
        AdminCreateEventTool::class,
        AdminBatchCreateEventsTool::class,
        AdminUpdateEventTool::class,
        AdminBatchUpdateEventsTool::class,
        AdminCreateRecordTool::class,
        AdminBatchCreateRecordsTool::class,
        AdminCreateGitHubIssueTool::class,
        AdminModerateEventTool::class,
        AdminTriageReportTool::class,
        AdminReviewContributionRequestTool::class,
        AdminReviewMembershipApplicationTool::class,
        AdminUpdateRecordTool::class,
        AdminBatchUpdateRecordsTool::class,
    ];
}
