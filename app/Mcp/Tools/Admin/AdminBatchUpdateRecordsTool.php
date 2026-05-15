<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminResourceService;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use function data_get;
use function data_set;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AdminBatchUpdateRecordsTool extends AbstractAdminWriteTool
{
    protected string $name = 'admin-batch-update-records';

    protected string $description = 'Use this to update multiple records for a writable admin resource in a single request. Each item is processed independently; the response contains a per-row result with status updated, validation_failed, not_found, or error. Set validate_only=true to preview normalized payloads and surface all validation errors upfront without persisting any changes. Include external_row_id in each item for idempotency tracking.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', [
            'level' => 'info',
            'data' => 'Validating and batch-updating records...',
        ]);

        yield $this->safeResponse(function () use ($request): ResponseFactory {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*' => ['array'],
                'validate_only' => ['sometimes', 'boolean'],
            ]);

            $resourceKey = (string) $validated['resource_key'];
            $validateOnly = (bool) ($validated['validate_only'] ?? false);

            /** @var list<array<string, mixed>> $items */
            $items = $validated['items'];

            return Response::structured($this->resourceService->batchUpdateRecords(
                resourceKey: $resourceKey,
                items: $items,
                actor: $actor,
                validateOnly: $validateOnly,
            ));
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource_key' => $schema->string()->required()->min(1)->description('Writable admin resource key (e.g. speakers, references, institutions).'),
            'items' => $schema->array()->required()->min(1)->max(100)->items(
                $schema->object([
                    'record_key' => $schema->string()->required()->description('Route key of the record to update (UUID or slug).'),
                    'external_row_id' => $schema->string()->nullable()->description('Optional caller-assigned row identifier for idempotency tracking.'),
                    'payload' => $schema->object()->required()->description('Update payload for this item. Fields must match the resource write schema. Use admin-get-write-schema with operation=update to discover available fields.'),
                ])
            )->description('Array of items to update. Each item must contain record_key and payload. Maximum 100 items per batch.'),
            'validate_only' => $schema->boolean()->default(false)->description('When true, validates all items without persisting. Returns per-row preview or validation error details.'),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     title?: string|null,
     *     description?: string|null,
     *     inputSchema?: array<string, mixed>,
     *     outputSchema?: array<string, mixed>,
     *     annotations?: array<string, mixed>|object,
     *     _meta?: array<string, mixed>
     * }
     */
    #[\Override]
    public function toArray(): array
    {
        $tool = parent::toArray();

        data_set($tool, 'inputSchema.properties.items.items.properties.payload.properties', (object) []);
        data_set($tool, 'inputSchema.properties.items.items.properties.payload.additionalProperties', true);

        /** @var list<string> $required */
        $required = data_get($tool, 'inputSchema.properties.items.items.required', []);

        if (! in_array('payload', $required, true)) {
            $required[] = 'payload';
            data_set($tool, 'inputSchema.properties.items.items.required', $required);
        }

        return $tool;
    }
}
