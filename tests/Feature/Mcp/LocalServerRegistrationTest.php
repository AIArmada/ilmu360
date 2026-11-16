<?php

use Laravel\Mcp\Facades\Mcp;

it('registers local MCP server handles for testing', function (): void {
    $servers = Mcp::servers();

    expect($servers)->toHaveKey('ilmu360-admin-local')
        ->and($servers)->toHaveKey('ilmu360-member-local')
        ->and(Mcp::getLocalServer('ilmu360-admin-local'))->not->toBeNull()
        ->and(Mcp::getLocalServer('ilmu360-member-local'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/admin'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/member'))->not->toBeNull();
});
