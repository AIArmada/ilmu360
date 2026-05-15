<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\AdminMcpController;
use App\Http\Controllers\Mcp\MemberMcpController;
use App\Http\Middleware\EnsureAdminMcpAccess;
use App\Http\Middleware\EnsureMemberMcpAccess;
use App\Http\Middleware\NormalizeMcpAcceptHeader;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Servers\MemberServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

Mcp::oauthRoutes('oauth/mcp');

Mcp::web('/mcp/admin', AdminServer::class)
    ->middleware([
        NormalizeMcpAcceptHeader::class,
        'auth:sanctum,api',
        EnsureAdminMcpAccess::class,
    ]);

Mcp::web('/mcp/member', MemberServer::class)
    ->middleware([
        NormalizeMcpAcceptHeader::class,
        'auth:sanctum,api',
        EnsureMemberMcpAccess::class,
    ]);

if (app()->environment(['local', 'testing'])) {
    Mcp::local('ilmu360-admin-local', AdminServer::class);
    Mcp::local('ilmu360-member-local', MemberServer::class);
}

Route::middleware([
    NormalizeMcpAcceptHeader::class,
    AddWwwAuthenticateHeader::class,
    'auth:sanctum,api',
    EnsureAdminMcpAccess::class,
])->group(function (): void {
    Route::get('/mcp/admin', [AdminMcpController::class, 'stream']);
    Route::delete('/mcp/admin', [AdminMcpController::class, 'destroy']);
});

Route::middleware([
    NormalizeMcpAcceptHeader::class,
    AddWwwAuthenticateHeader::class,
    'auth:sanctum,api',
    EnsureMemberMcpAccess::class,
])->group(function (): void {
    Route::get('/mcp/member', [MemberMcpController::class, 'stream']);
    Route::delete('/mcp/member', [MemberMcpController::class, 'destroy']);
});
