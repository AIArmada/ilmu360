<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Support\Mcp\MemberMcpDocumentationPreflight;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Methods\ReadResource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

class MemberReadResourceWithDocumentationPreflight extends ReadResource
{
    /**
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws BindingResolutionException
     */
    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $uri = $request->get('uri');

        try {
            $resource = $this->resolveResource($uri, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32002, $request->id);
        }

        $response = $this->invokeResource($resource, $uri);

        if ($uri === MemberMcpDocumentationPreflight::GUIDE_RESOURCE_URI) {
            /** @var Request $mcpRequest */
            $mcpRequest = Container::getInstance()->make('mcp.request');
            app(MemberMcpDocumentationPreflight::class)->markGuideInContext($mcpRequest);
        }

        return is_iterable($response)
            ? $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($resource, $uri))
            : $this->toJsonRpcResponse($request, $response, $this->serializable($resource, $uri));
    }
}
