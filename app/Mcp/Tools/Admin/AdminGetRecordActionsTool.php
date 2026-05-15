<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminRecordActionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminGetRecordActionsTool extends AbstractAdminTool
{
    protected string $name = 'admin-get-record-actions';

    protected string $description = 'Use this when you need to discover the next available workflow actions or follow-up MCP tool calls for one admin record. Returns only actions that are currently valid given the record\'s state.';

    public function __construct(
        private readonly AdminRecordActionService $recordActionService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'resource_key' => ['required', 'string'],
                'record_key' => ['required', 'string'],
            ]);

            return $this->recordActionService->describe(
                resourceKey: (string) $validated['resource_key'],
                recordKey: (string) $validated['record_key'],
                actor: $actor,
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
