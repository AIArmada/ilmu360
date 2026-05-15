<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminResourceService;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AdminCreateRecordTool extends AbstractAdminWriteTool
{
    protected string $name = 'admin-create-record';

    protected string $description = 'Use this when you need to create a new admin resource record. Fetch the write schema first with admin-get-write-schema. For creating events, prefer admin-create-event which resolves organizer and venue by name.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', [
            'level' => 'info',
            'data' => 'Validating and creating record...',
        ]);

        yield $this->safeResponse(function () use ($request): ResponseFactory {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'payload' => ['required', 'array'],
                'validate_only' => ['sometimes', 'boolean'],
                'apply_defaults' => ['sometimes', 'boolean'],
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $validated['payload'];
            $resourceKey = (string) $validated['resource_key'];
            $validateOnly = (bool) ($validated['validate_only'] ?? false);
            $applyDefaults = (bool) ($validated['apply_defaults'] ?? false);

            $this->ensureDestructiveMediaClearFlagsAreUnsupported($payload);
            $schemaResponse = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'create',
                actor: $actor,
            );
            $normalizedMediaPayload = $this->normalizeMcpMediaPayload($payload, $schemaResponse);

            try {
                if ($validateOnly && $applyDefaults) {
                    $normalizedMediaPayload['payload'] = $this->payloadWithSchemaDefaults($normalizedMediaPayload['payload'], $schemaResponse);
                }

                $payload = $this->normalizePayloadForWriteTool($resourceKey, $normalizedMediaPayload['payload']);

                return Response::structured($this->resourceService->storeRecord(
                    resourceKey: $resourceKey,
                    payload: $payload,
                    actor: $actor,
                    validateOnly: $validateOnly,
                ));
            } catch (ValidationException $exception) {
                return $this->writeValidationErrorResponse(
                    exception: $exception,
                    payload: $payload,
                    schemaResponse: $schemaResponse,
                    resourceKey: $resourceKey,
                    operation: 'create',
                    validateOnly: $validateOnly,
                    applyDefaults: $applyDefaults,
                );
            } finally {
                $this->cleanupMcpMediaPayload($normalizedMediaPayload);
            }
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
            'payload' => $schema->object()->required()->description('Required object containing writable fields for the target resource.'),
            'validate_only' => $schema->boolean()->default(false)->description('When true, validates and normalizes the payload without persisting a record.'),
            'apply_defaults' => $schema->boolean()->default(false)->description('Preview-only helper. Honored only when validate_only=true to merge schema defaults into validation feedback; ignored for persisted creates.'),
        ];
    }

    /**
     * Add ChatGPT file params metadata.
     * Note: Media fields are dynamic and depend on resource_key; file params work via the payload object.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $tool = parent::toArray();
        $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        $required = array_values(array_unique(array_filter(
            [
                ...array_values(is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : []),
                'resource_key',
                'payload',
            ],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));

        $properties['payload'] = array_merge(
            [
                'type' => 'object',
                'description' => 'Required object containing writable fields for the target resource.',
            ],
            is_array($properties['payload'] ?? null) ? $properties['payload'] : [],
        );

        $tool['inputSchema'] = array_merge($inputSchema, [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ]);

        $tool['_meta'] = array_merge(
            is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [],
            [
                'openai/note' => 'Media file descriptors are passed inside the payload object field for media-capable resources (Event, Institution, Reference, Report, Speaker, Venue, Series, DonationChannel, Inspiration, Space). Pass {content_base64, filename} or {content_url, filename}.',
            ],
        );

        return $tool;
    }
}
