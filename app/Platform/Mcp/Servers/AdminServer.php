<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Servers;

use App\Platform\Diagnostics\EntryPoints\Mcp\Tools\AdminReadDebugLogTool;
use App\Platform\Mcp\McpSurfaceRegistry;
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
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

#[Name('ilmu360-admin')]
#[Version('1.0.0')]
#[Instructions('Authenticated admin MCP server with parity to the Filament admin resource API for listing resources, reading records, traversing relations, getting record-specific next-step MCP actions, discovering MCP write schemas, getting explicit workflow schema descriptors for event moderation, report triage, contribution-request review, and membership-application review, writing supported fields for donation-channel, event, inspiration, institution, report, person, venue, reference, address-area, series, and space records. Reward resources are read-only through the generic admin API and MCP; any future reward mutation must call an AIArmada\\Rewards package Action through the Rewards feature. No reward-specific MCP tools are exposed. The server also runs explicit workflow actions for those admin review surfaces. Event cover images are fixed at 16:9 and event poster images are fixed at 3:4. Media fields use JSON base64 file descriptors when advertised by the schema. To generate event images: use the `admin-event-cover-image-prompt` or `admin-event-poster-image-prompt` prompts to get the engineered prompt text and brand reference images, generate the image using ChatGPT native image generation, then upload the result with `admin-upload-event-cover-image` or `admin-upload-event-poster-image`. Entity-selection hint for record search: institution-type nouns (`masjid`, `surau`, `madrasah`, `maahad`, `pondok`, `sekolah`, `kolej`, `universiti`) should be searched as `institutions` first; venue-type nouns (`dewan`, `auditorium`, `stadium`, `perpustakaan`, `padang`, `hotel`) should be searched as `venues` first; `spaces` are finer-grained sublocations inside institutions and should not be the default first lookup for named mosques or surau. The server exposes the primary admin MCP guide as a raw markdown resource, read-only `search` / `fetch` documentation tools that expose the broader verified admin docs catalog for tool-centric clients such as ChatGPT and the OpenAI Responses MCP integration, plus a `documentation-tool-routing` prompt that explains when to use the guide and the documentation tools. Operational MCP tool calls are rejected until `docs-admin-mcp-guide` has been fetched or the guide resource has been read in the current initialized MCP session.')]
class AdminServer extends Ilmu360Server
{
    #[\Override]
    public int $defaultPaginationLength = 50;

    public function __construct(Transport $transport, private readonly McpSurfaceRegistry $surfaces)
    {
        parent::__construct($transport);
    }

    #[\Override]
    protected function boot(): void
    {
        $this->addCapability(self::CAPABILITY_COMPLETIONS);

        foreach ($this->surfaces->adminMethods() as $method => $handler) {
            $this->addMethod($method, $handler);
        }

        // Platform-owned generic tools stay with the server; feature owners
        // contribute their tooling through the surface registry in boot order.
        $this->tools = [
            ...$this->surfaces->adminTools(),
            ...$this->platformTools(),
        ];

        $this->prompts = $this->surfaces->adminPrompts();
        $this->resources = $this->surfaces->adminResources();
    }

    /**
     * @return list<class-string<Tool>>
     */
    private function platformTools(): array
    {
        return [
            AdminReadDebugLogTool::class,
            AdminListResourcesTool::class,
            AdminGetResourceMetaTool::class,
            AdminListRecordsTool::class,
            AdminListRelatedRecordsTool::class,
            AdminGetRecordTool::class,
            AdminGetRecordMediaTool::class,
            AdminGetRecordActionsTool::class,
            AdminGetWriteSchemaTool::class,
            AdminCreateRecordTool::class,
            AdminBatchCreateRecordsTool::class,
            AdminUpdateRecordTool::class,
            AdminBatchUpdateRecordsTool::class,
        ];
    }
}
