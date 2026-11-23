<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Servers;

use App\Platform\Mcp\McpSurfaceRegistry;
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
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

#[Name('ilmu360-member')]
#[Version('1.0.0')]
#[Instructions('Authenticated member MCP server for Ahli-scoped institutions, people, references, and related events. Supports scoped resource discovery, record reads, record-specific next-step MCP actions, schema-guided updates for writable Ahli records when the authenticated member has the matching scoped permissions, plus member-side workflow tools for contribution-request queues and membership applications that mirror the Ahli dashboard. To submit an event, get IDs with the event submission lookup and options tools, validate with `member-validate-event-submission`, and call `member-submit-event` after confirmation. Event cover images are fixed at 16:9 and event poster images are fixed at 3:4. Media fields use JSON base64 file descriptors when advertised by the schema. To generate event images: use the `member-event-cover-image-prompt` or `member-event-poster-image-prompt` prompts to get the engineered prompt text and brand reference images, generate the image using ChatGPT native image generation, then upload the result with `member-upload-event-cover-image` or `member-upload-event-poster-image`. Entity-selection hint for record search: institution-type nouns (`masjid`, `surau`, `madrasah`, `maahad`, `pondok`, `sekolah`, `kolej`, `universiti`) should be searched as `institutions` first; venue-type nouns (`dewan`, `auditorium`, `stadium`, `perpustakaan`, `padang`, `hotel`) should be searched as `venues` first; `spaces` are finer-grained sublocations inside institutions and should not be the default first lookup for named mosques or surau. The server exposes the primary member MCP guide as a raw markdown resource, read-only `search` / `fetch` documentation tools that expose the broader verified member docs catalog for tool-centric clients such as ChatGPT and the OpenAI Responses MCP integration, a `file://docs/{filename}` resource template for reading those verified documentation URLs directly, plus a `documentation-tool-routing` prompt that explains when to use the guide and the documentation tools. Operational MCP tool calls are rejected until `docs-member-mcp-guide` has been fetched or the guide resource has been read in the current MCP context. Designed for bearer-token clients such as VS Code, ChatGPT, Gemini, Grok, Claude, and Opencode.')]
class MemberServer extends Ilmu360Server
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

        foreach ($this->surfaces->memberMethods() as $method => $handler) {
            $this->addMethod($method, $handler);
        }

        // Platform-owned generic tools stay with the server; feature owners
        // contribute their tooling through the surface registry in boot order.
        $this->tools = [
            ...$this->surfaces->memberTools(),
            ...$this->platformTools(),
        ];

        $this->prompts = $this->surfaces->memberPrompts();
        $this->resources = $this->surfaces->memberResources();
    }

    /**
     * @return list<class-string<Tool>>
     */
    private function platformTools(): array
    {
        return [
            MemberListResourcesTool::class,
            MemberGetResourceMetaTool::class,
            MemberListRecordsTool::class,
            MemberListRelatedRecordsTool::class,
            MemberGetRecordTool::class,
            MemberGetRecordActionsTool::class,
            MemberGetWriteSchemaTool::class,
            MemberUpdateRecordTool::class,
        ];
    }
}
