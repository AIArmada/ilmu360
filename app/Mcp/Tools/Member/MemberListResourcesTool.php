<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Support\Api\Member\MemberResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemberListResourcesTool extends AbstractMemberTool
{
    protected string $name = 'member-list-resources';

    protected string $description = 'Use this when you need to discover which Ahli-scoped resources are available on this server. Returns a compact summary by default; set verbose=true for full field and filter metadata.';

    public function __construct(
        private readonly MemberResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'verbose' => ['sometimes', 'nullable', 'boolean'],
            ]);

            return $this->resourceService->manifest(
                compact: ! (bool) ($validated['verbose'] ?? false),
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
        ];
    }
}
