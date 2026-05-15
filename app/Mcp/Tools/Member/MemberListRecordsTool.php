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
class MemberListRecordsTool extends AbstractMemberTool
{
    protected string $name = 'member-list-records';

    protected string $description = 'Use this when you need to list, search, or filter records for one Ahli-scoped resource. Supports free-text search, date-range filters, and pagination. Do not use for fetching a single record by key; use member-get-record for that.';

    public function __construct(
        private readonly MemberResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'search' => ['sometimes', 'nullable', 'string'],
                'starts_after' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'starts_before' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'starts_on_local_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            ]);

            return $this->resourceService->listRecords(
                resourceKey: (string) $validated['resource_key'],
                search: (string) ($validated['search'] ?? ''),
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? 15),
                startsAfter: $validated['starts_after'] ?? null,
                startsBefore: $validated['starts_before'] ?? null,
                startsOnLocalDate: $validated['starts_on_local_date'] ?? null,
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
            'search' => $schema->string()->nullable(),
            'starts_after' => $schema->string()->nullable(),
            'starts_before' => $schema->string()->nullable(),
            'starts_on_local_date' => $schema->string()->nullable(),
            'page' => $schema->integer()->min(1)->default(1)->nullable(),
            'per_page' => $schema->integer()->min(1)->max(100)->default(15)->nullable(),
        ];
    }
}
