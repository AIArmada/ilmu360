<?php

declare(strict_types=1);

use App\Platform\Mcp\EntryPoints\Http\Middleware\EnsureAdminMcpAccess;
use App\Platform\Mcp\EntryPoints\Http\Middleware\EnsureMemberMcpAccess;
use App\Platform\Mcp\Servers\AdminServer;
use App\Platform\Mcp\Servers\MemberServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes('oauth/mcp');

Mcp::web('/mcp/admin', AdminServer::class)
    ->middleware([
        'auth:sanctum,api',
        EnsureAdminMcpAccess::class,
    ]);

Mcp::web('/mcp/member', MemberServer::class)
    ->middleware([
        'auth:sanctum,api',
        EnsureMemberMcpAccess::class,
    ]);

if (app()->environment(['local', 'testing'])) {
    Mcp::local('ilmu360-admin-local', AdminServer::class);
    Mcp::local('ilmu360-member-local', MemberServer::class);
}
