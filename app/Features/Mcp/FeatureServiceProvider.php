<?php

declare(strict_types=1);

namespace App\Features\Mcp;

use App\Features\Mcp\Api\McpDocumentationPreflightState;
use App\Features\Mcp\EntryPoints\Mcp\Methods\CallToolWithDocumentationPreflight;
use App\Features\Mcp\EntryPoints\Mcp\Methods\MemberCallToolWithDocumentationPreflight;
use App\Features\Mcp\EntryPoints\Mcp\Prompts\DocumentationToolRoutingPrompt;
use App\Features\Mcp\EntryPoints\Mcp\Prompts\MemberDocumentationToolRoutingPrompt;
use App\Features\Mcp\EntryPoints\Mcp\Resources\AdminVerifiedDocumentationResource;
use App\Features\Mcp\EntryPoints\Mcp\Resources\McpGuideResource;
use App\Features\Mcp\EntryPoints\Mcp\Resources\MemberMcpGuideResource;
use App\Features\Mcp\EntryPoints\Mcp\Resources\MemberVerifiedDocumentationResource;
use App\Features\Mcp\EntryPoints\Mcp\Tools\AdminDocumentationFetchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\AdminDocumentationSearchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\MemberDocumentationFetchTool;
use App\Features\Mcp\EntryPoints\Mcp\Tools\MemberDocumentationSearchTool;
use App\Features\Mcp\Persistence\McpDocumentationPreflightCacheStore;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(McpDocumentationPreflightState::class, McpDocumentationPreflightCacheStore::class);
    }

    public function boot(): void
    {
        parent::boot();

        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminTool(AdminDocumentationSearchTool::class);
        $surfaces->registerAdminTool(AdminDocumentationFetchTool::class);
        $surfaces->registerAdminPrompt(DocumentationToolRoutingPrompt::class);
        $surfaces->registerAdminResource(McpGuideResource::class);
        $surfaces->registerAdminResource(AdminVerifiedDocumentationResource::class);
        $surfaces->registerAdminMethod('tools/call', CallToolWithDocumentationPreflight::class);

        $surfaces->registerMemberTool(MemberDocumentationSearchTool::class);
        $surfaces->registerMemberTool(MemberDocumentationFetchTool::class);
        $surfaces->registerMemberPrompt(MemberDocumentationToolRoutingPrompt::class);
        $surfaces->registerMemberResource(MemberMcpGuideResource::class);
        $surfaces->registerMemberResource(MemberVerifiedDocumentationResource::class);
        $surfaces->registerMemberMethod('tools/call', MemberCallToolWithDocumentationPreflight::class);
    }
}
