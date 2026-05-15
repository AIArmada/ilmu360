<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminListResourcesTool extends AbstractAdminTool
{
    protected string $name = 'admin-list-resources';

    protected string $description = 'Use this when you need to discover which admin resources are available on this server. Returns a compact summary by default; set verbose=true for full field and filter metadata.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'verbose' => ['sometimes', 'nullable', 'boolean'],
                'writable_only' => ['sometimes', 'nullable', 'boolean'],
            ]);

            return $this->resourceService->manifest(
                compact: ! (bool) ($validated['verbose'] ?? false),
                writableOnly: (bool) ($validated['writable_only'] ?? false),
            );
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'verbose' => $schema->boolean()->default(false)->nullable(),
            'writable_only' => $schema->boolean()->default(false)->nullable(),
        ];
    }
}
