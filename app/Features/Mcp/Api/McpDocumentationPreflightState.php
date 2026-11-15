<?php

declare(strict_types=1);

namespace App\Features\Mcp\Api;

interface McpDocumentationPreflightState
{
    /**
     * @param  list<string>  $contextKeys
     */
    public function markGuideSeen(McpDocumentationAudience $audience, array $contextKeys): void;

    /**
     * @param  list<string>  $contextKeys
     */
    public function hasGuideBeenSeen(McpDocumentationAudience $audience, array $contextKeys): bool;
}
