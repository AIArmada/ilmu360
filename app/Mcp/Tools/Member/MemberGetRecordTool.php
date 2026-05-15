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
class MemberGetRecordTool extends AbstractMemberTool
{
    protected string $name = 'member-get-record';

    protected string $description = 'Use this when you need the full details of one Ahli-scoped member record. Requires both resource_key and record_key. Do not use for listing multiple records; use member-list-records for that.';

    public function __construct(
        private readonly MemberResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'record_key' => ['required', 'string'],
            ]);

            return $this->resourceService->showRecord(
                resourceKey: (string) $validated['resource_key'],
                recordKey: (string) $validated['record_key'],
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
            'resource_key' => $schema->string()->required()->min(1),
            'record_key' => $schema->string()->required()->min(1),
        ];
    }
}
