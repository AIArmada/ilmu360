<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Servers;

use Illuminate\Container\Container;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server as BaseServer;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

abstract class Ilmu360Server extends BaseServer
{
    #[\Override]
    protected function icons(): array
    {
        return [Icon::from('images/favicon-96x96.png', 'image/png', ['96x96'])];
    }

    /**
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    #[\Override]
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $container = Container::getInstance();

        $container->instance('mcp.transport', $this->transport);

        try {
            return parent::runMethodHandle($request, $context);
        } finally {
            $container->forgetInstance('mcp.transport');
        }
    }
}
