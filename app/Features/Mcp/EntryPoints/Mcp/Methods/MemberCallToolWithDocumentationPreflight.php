<?php

declare(strict_types=1);

namespace App\Features\Mcp\EntryPoints\Mcp\Methods;

use App\Features\Mcp\Services\MemberMcpDocumentationPreflight;
use App\Features\Mcp\Services\MemberVerifiedDocumentationCatalog;
use App\Platform\Mcp\Methods\Concerns\BuildsToolJsonRpcResponse;
use App\Platform\Mcp\Methods\Concerns\LogsMcpToolExecution;
use Generator;
use Illuminate\Container\Container;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

class MemberCallToolWithDocumentationPreflight extends CallTool implements Errable, Method
{
    use BuildsToolJsonRpcResponse;
    use LogsMcpToolExecution;

    /**
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->get('name');
        if (! is_string($name)) {
            return parent::handle($request, $context);
        }

        $tool = $context
            ->tools()
            ->first(
                fn ($tool): bool => $tool->name() === $name,
                fn () => throw new JsonRpcException(
                    "Tool [{$name}] not found.",
                    -32602,
                    $request->id,
                ));

        $startedAt = microtime(true);
        $toolName = $tool->name();
        $this->logToolExecutionStarted($request, $toolName, 'member');

        $container = Container::getInstance();

        /** @var Request $mcpRequest */
        $mcpRequest = $container->make('mcp.request');

        /** @var MemberMcpDocumentationPreflight $documentationPreflight */
        $documentationPreflight = $container->make(MemberMcpDocumentationPreflight::class);
        $transport = $container->bound('mcp.transport')
            ? $container->make('mcp.transport')
            : null;

        if ($documentationPreflight->shouldBlockOperationalToolCall($mcpRequest, $toolName, $transport)) {
            /** @var MemberVerifiedDocumentationCatalog $catalog */
            $catalog = $container->make(MemberVerifiedDocumentationCatalog::class);
            $guideDocument = $catalog->fetch(MemberMcpDocumentationPreflight::GUIDE_DOCUMENT_ID);

            if ($guideDocument !== null) {
                $documentationPreflight->markGuideInContext($mcpRequest);
                $this->logToolExecutionBlocked($request, $toolName, 'member', 'documentation_preflight');

                return $this->toJsonRpcResponse(
                    $request,
                    $documentationPreflight->guideInjectionResponse($toolName, $guideDocument),
                    $this->serializable($tool),
                );
            }
        }

        try {
            $response = parent::handle($request, $context);

            $this->logToolExecutionCompleted(
                $request,
                $toolName,
                'member',
                $response instanceof Generator,
                $startedAt,
            );

            return $response;
        } catch (Throwable $exception) {
            $this->logToolExecutionFailed($request, $toolName, 'member', $exception, $startedAt);

            throw $exception;
        }
    }
}
