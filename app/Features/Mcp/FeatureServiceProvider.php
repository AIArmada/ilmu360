<?php

declare(strict_types=1);

namespace App\Features\Mcp;

use App\Features\Mcp\Api\McpDocumentationPreflightState;
use App\Features\Mcp\Persistence\McpDocumentationPreflightCacheStore;
use App\Platform\FeatureModule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(McpDocumentationPreflightState::class, McpDocumentationPreflightCacheStore::class);
    }
}
