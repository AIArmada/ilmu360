<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use Illuminate\Container\Container;
use Laravel\Mcp\Server as BaseServer;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

abstract class Ilmu360Server extends BaseServer
{
    /**
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    #[\Override]
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $container = Container::getInstance();

        $methodClass = $container->make(
            $this->methods[$request->method],
        );

        $container->instance('mcp.request', $request->toRequest());
        $container->instance('mcp.transport', $this->transport);

        try {
            return $methodClass->handle($request, $context);
        } finally {
            $container->forgetInstance('mcp.request');
            $container->forgetInstance('mcp.transport');
        }
    }
}
