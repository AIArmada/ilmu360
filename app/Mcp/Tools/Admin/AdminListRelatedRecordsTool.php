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
class AdminListRelatedRecordsTool extends AbstractAdminTool
{
    protected string $name = 'admin-list-related-records';

    protected string $description = 'Use this when you need to list records related to one admin record (e.g. speakers for an event, events for a venue). Supports search and pagination. Do not use for listing top-level records; use admin-list-records for that.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'record_key' => ['required', 'string'],
                'relation' => ['required', 'string'],
                'search' => ['sometimes', 'nullable', 'string'],
                'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            ]);

            return $this->resourceService->listRelatedRecords(
                resourceKey: (string) $validated['resource_key'],
                recordKey: (string) $validated['record_key'],
                relation: (string) $validated['relation'],
                search: (string) ($validated['search'] ?? ''),
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? 15),
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
            'relation' => $schema->string()->required()->min(1),
            'search' => $schema->string()->nullable(),
            'page' => $schema->integer()->min(1)->default(1)->nullable(),
            'per_page' => $schema->integer()->min(1)->max(100)->default(15)->nullable(),
        ];
    }
}
