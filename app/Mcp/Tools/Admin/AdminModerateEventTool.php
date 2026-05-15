<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Api\Admin\AdminEventModerationService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Moderation\EventModerationWorkflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
class AdminModerateEventTool extends AbstractAdminTool
{
    protected string $name = 'admin-moderate-event';

    protected string $description = 'Use this when you need to apply a moderation action to an event: submit for review, approve, request changes, reject, cancel, reconsider, remoderate, or revert to draft. Fetch the moderation schema first with admin-get-event-moderation-schema.';

    public function __construct(
        private readonly AdminEventModerationService $moderationService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'record_key' => ['required', 'string'],
                'action' => ['required', 'string'],
                'reason_code' => ['sometimes', 'nullable', 'string'],
                'note' => ['sometimes', 'nullable', 'string'],
            ]);

            return $this->moderationService->moderate(
                recordKey: (string) $validated['record_key'],
                payload: $validated,
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
            'record_key' => $schema->string()->required()->min(1),
            'action' => $schema->string()->required()->enum(EventModerationWorkflow::allActionKeys()),
            'reason_code' => $schema->string()->nullable()->enum(array_keys(EventModerationWorkflow::reasonOptions())),
            'note' => $schema->string()->nullable(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $this->moderationService->canModerate($user);
    }
}
