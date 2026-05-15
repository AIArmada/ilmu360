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
class MemberGetResourceMetaTool extends AbstractMemberTool
{
    protected string $name = 'member-get-resource-meta';

    protected string $description = 'Use this when you need to discover field definitions, available filter options, or sort options for one Ahli-scoped member resource. Do not use for listing records; use member-list-records for that.';

    public function __construct(
        private readonly MemberResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
            ]);

            return $this->resourceService->resourceMeta((string) $validated['resource_key']);
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource_key' => $schema->string()->required()->min(1),
        ];
    }
}
