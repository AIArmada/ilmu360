<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Support\Api\Member\MemberResourceService;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class MemberUpdateRecordTool extends AbstractMemberWriteTool
{
    protected string $name = 'member-update-record';

    protected string $description = 'Use this when you need to update an Ahli-scoped member resource record. For event records, the payload can include cover/poster/gallery image descriptors alongside regular fields. Do not use to create new records.';

    public function __construct(
        private readonly MemberResourceService $resourceService,
    ) {}

    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', [
            'level' => 'info',
            'data' => 'Validating and updating record...',
        ]);

        yield $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'record_key' => ['required', 'string'],
                'payload' => ['required', 'array'],
                'validate_only' => ['sometimes', 'boolean'],
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $validated['payload'];
            $resourceKey = (string) $validated['resource_key'];
            $recordKey = (string) $validated['record_key'];

            $this->ensureDestructiveMediaClearFlagsAreUnsupported($payload);
            $schemaResponse = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                recordKey: $recordKey,
                actor: $actor,
            );
            $normalizedMediaPayload = $this->normalizeMcpMediaPayload($payload, $schemaResponse);

            try {
                $payload = $this->normalizePayloadForWriteTool($resourceKey, $normalizedMediaPayload['payload']);

                return $this->resourceService->updateRecord(
                    resourceKey: $resourceKey,
                    recordKey: $recordKey,
                    payload: $payload,
                    actor: $actor,
                    validateOnly: (bool) ($validated['validate_only'] ?? false),
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
            'record_key' => $schema->string()->required()->min(1),
            'payload' => $schema->object()->required(),
            'validate_only' => $schema->boolean()->default(false)->nullable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $tool = parent::toArray();

        $tool['_meta'] = array_merge(
            is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [],
            [
                'openai/note' => 'Media file descriptors are accepted inside payload for media-capable resources. Pass {content_base64, filename} or {content_url, filename}.',
            ],
        );

        return $tool;
    }
}
