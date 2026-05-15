<?php

use Laravel\Mcp\Facades\Mcp;

it('registers local MCP server handles for testing', function (): void {
    $servers = Mcp::servers();

    expect(array_key_exists('ilmu360-admin-local', $servers))->toBeTrue();
    expect(array_key_exists('ilmu360-member-local', $servers))->toBeTrue();

    expect(Mcp::getLocalServer('ilmu360-admin-local'))->not->toBeNull();
    expect(Mcp::getLocalServer('ilmu360-member-local'))->not->toBeNull();

    expect(Mcp::getWebServer('mcp/admin'))->not->toBeNull();
    expect(Mcp::getWebServer('mcp/member'))->not->toBeNull();
});
