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
class AdminGetRecordMediaTool extends AbstractAdminTool
{
    protected string $name = 'admin-get-record-media';

    protected string $description = 'Returns the media attachments for one admin record. Use this to verify uploads, check collection names, or get media URLs without fetching the full record payload. Faster and more compact than admin-get-record for media verification. For events, also returns card_image_url, poster_url, and cover_url.';

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
            ]);

            return $this->resourceService->recordMedia(
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
