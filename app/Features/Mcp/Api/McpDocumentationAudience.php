<?php

declare(strict_types=1);

namespace App\Features\Mcp\Api;

enum McpDocumentationAudience: string
{
    case Admin = 'admin';

    case Member = 'member';
}
