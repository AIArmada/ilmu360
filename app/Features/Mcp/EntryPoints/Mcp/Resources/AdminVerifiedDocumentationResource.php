<?php

declare(strict_types=1);

namespace App\Features\Mcp\EntryPoints\Mcp\Resources;

use App\Platform\Documentation\DocumentationLibrary;

final class AdminVerifiedDocumentationResource extends VerifiedDocumentationResource
{
    #[\Override]
    protected function audience(): string
    {
        return DocumentationLibrary::AUDIENCE_MCP_ADMIN;
    }
}
