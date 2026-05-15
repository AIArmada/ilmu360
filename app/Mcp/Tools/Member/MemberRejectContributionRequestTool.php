<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
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
class MemberRejectContributionRequestTool extends AbstractMemberTool
{
    protected string $name = 'member-reject-contribution-request';

    protected string $description = 'Use this when you need to reject a reviewable contribution request through the Ahli workflow. Do not use for admin-level review; use admin-review-contribution-request for that.';

    public function __construct(
        private readonly MemberContributionWorkflowService $workflowService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'request_id' => ['required', 'string'],
                'reason_code' => ['required', 'string'],
                'reviewer_note' => ['sometimes', 'nullable', 'string'],
            ]);

            return $this->workflowService->reject(
                requestId: (string) $validated['request_id'],
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
            'request_id' => $schema->string()->required()->min(1),
            'reason_code' => $schema->string()->required()->enum(array_keys(ContributionRequestPresenter::rejectionReasonOptions())),
            'reviewer_note' => $schema->string()->nullable(),
        ];
    }
}
