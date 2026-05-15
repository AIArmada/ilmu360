<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Support\Api\Member\MemberContributionWorkflowService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemberListContributionRequestsTool extends AbstractMemberTool
{
    protected string $name = 'member-list-contribution-requests';

    protected string $description = 'Use this when you need to list the authenticated member\'s own contribution requests or view pending approvals available through the Ahli workflow. Do not use for admin-level contribution request management.';

    public function __construct(
        private readonly MemberContributionWorkflowService $workflowService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);
            $this->validateArguments($request, []);

            return $this->workflowService->list($actor);
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
