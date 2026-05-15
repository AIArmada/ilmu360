<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Support\Api\Member\MemberContributionWorkflowService;
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
class MemberCancelContributionRequestTool extends AbstractMemberTool
{
    protected string $name = 'member-cancel-contribution-request';

    protected string $description = 'Use this when the authenticated Ahli/member needs to cancel a pending contribution request they own. Do not use for cancelling requests owned by other members.';

    public function __construct(
        private readonly MemberContributionWorkflowService $workflowService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'request_id' => ['required', 'string'],
            ]);

            return $this->workflowService->cancel(
                requestId: (string) $validated['request_id'],
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
            'request_id' => $schema->string()->required()->min(1),
        ];
    }
}
