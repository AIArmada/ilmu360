<?php

declare(strict_types=1);

namespace App\Mcp\Methods\Concerns;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Methods\Concerns\InteractsWithResponses;
use Laravel\Mcp\Server\Tool;

trait BuildsToolJsonRpcResponse
{
    use InteractsWithResponses;

    protected function serializable(Tool $tool): callable
    {
        return fn (ResponseFactory $factory): array => $factory->mergeStructuredContent(
            $factory->mergeMeta([
                'content' => $factory->responses()->map(
                    fn (Response $response): array => $response->content()->toTool($tool),
                )->all(),
                'isError' => $factory->responses()->contains(
                    fn (Response $response): bool => $response->isError(),
                ),
            ]),
        );
    }
}
