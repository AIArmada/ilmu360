<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminResourceService;
use App\Support\Mcp\McpWriteSchemaFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminGetWriteSchemaTool extends AbstractAdminWriteTool
{
    protected string $name = 'admin-get-write-schema';

    protected string $title = 'Inspect Admin Write Schema';

    protected string $description = 'Use this when you need to see the full field contract for creating or updating an admin resource before composing a payload. Read-only — does not create or mutate any records.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
        private readonly McpWriteSchemaFormatter $schemaFormatter,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'operation' => ['required', 'string', 'in:create,update'],
                'record_key' => ['nullable', 'string', 'required_if:operation,update'],
            ]);

            $resourceKey = (string) $validated['resource_key'];
            $operation = (string) $validated['operation'];
            $recordKey = isset($validated['record_key']) ? (string) $validated['record_key'] : null;

            $response = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: $operation,
                recordKey: $recordKey,
                actor: $actor,
            );

            if (is_array($response['data']['schema'] ?? null)) {
                /** @var array<string, mixed> $schema */
                $schema = $response['data']['schema'];
                $toolName = $this->toolName($resourceKey, $operation);
                $response['data']['schema'] = $this->schemaFormatter->formatSchema(
                    $schema,
                    $toolName,
                    $this->toolArguments($resourceKey, $operation, $recordKey),
                );
            }

            return $response;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toolArguments(string $resourceKey, string $operation, ?string $recordKey): array
    {
        if ($operation === 'create' && $resourceKey === 'events') {
            return [
                'title' => 'string',
                'event_date' => 'YYYY-MM-DD',
                'prayer_time' => 'string',
                'event_type' => 'array<string>',
                'organizer_type' => 'institution|speaker',
                'organizer_key' => 'route_key',
                'institution_key' => 'route_key',
                'validate_only' => false,
                'apply_defaults' => false,
            ];
        }

        if ($operation === 'update') {
            return [
                'resource_key' => $resourceKey,
                'record_key' => $recordKey ?? 'record',
                'payload' => 'object',
                'validate_only' => false,
                'apply_defaults' => false,
            ];
        }

        return [
            'resource_key' => $resourceKey,
            'payload' => 'object',
            'validate_only' => false,
            'apply_defaults' => false,
        ];
    }

    private function toolName(string $resourceKey, string $operation): string
    {
        if ($operation === 'create' && $resourceKey === 'events') {
            return 'admin-create-event';
        }

        return $operation === 'create' ? 'admin-create-record' : 'admin-update-record';
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource_key' => $schema->string()->required()->min(1),
            'operation' => $schema->string()->required()->enum(['create', 'update']),
            'record_key' => $schema->string()->nullable(),
        ];
    }
}
