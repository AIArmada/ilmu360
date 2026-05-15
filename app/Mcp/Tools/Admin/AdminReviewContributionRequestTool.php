<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Support\Api\Admin\AdminContributionRequestReviewService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
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
class AdminReviewContributionRequestTool extends AbstractAdminTool
{
    protected string $name = 'admin-review-contribution-request';

    protected string $description = 'Use this when you need to approve or reject a pending contribution request. Fetch the review schema first with admin-get-contribution-request-review-schema. Do not use for reading request details; use admin-get-record for that.';

    public function __construct(
        private readonly AdminContributionRequestReviewService $reviewService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'record_key' => ['required', 'string'],
                'action' => ['required', 'string'],
                'reason_code' => ['sometimes', 'nullable', 'string'],
                'reviewer_note' => ['sometimes', 'nullable', 'string'],
            ]);

            return $this->reviewService->review(
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
            'action' => $schema->string()->required()->enum(['approve', 'reject']),
            'reason_code' => $schema->string()->nullable()->enum(array_keys(ContributionRequestPresenter::rejectionReasonOptions())),
            'reviewer_note' => $schema->string()->nullable(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $this->reviewService->canReview($user);
    }
}
