<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceRegistry;
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
class AdminReviewMembershipClaimTool extends AbstractAdminTool
{
    protected string $name = 'admin-review-membership-claim';

    protected string $description = 'Use this when you need to approve or reject a pending membership claim. Fetch the review schema first with admin-get-membership-claim-review-schema. Do not use for reading claim details; use admin-get-record for that.';

    public function __construct(
        private ApproveMembershipApplicationAction $approveAction,
        private RejectMembershipApplicationAction $rejectAction,
        private AdminResourceRegistry $registry,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'record_key' => ['required', 'string'],
                'action' => ['required', 'string'],
                'granted_role' => ['sometimes', 'nullable', 'string'],
                'reviewer_note' => ['sometimes', 'nullable', 'string'],
            ]);

            /** @var MembershipApplication $application */
            $application = $this->registry->resolveRecord(MembershipClaimResource::class, (string) $validated['record_key']);
            $note = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

            match ((string) $validated['action']) {
                'approve' => $this->approveAction->handle(
                    $application,
                    $actor,
                    MemberRole::tryFrom((string) $validated['granted_role']) ?? MemberRole::Editor,
                    $note,
                ),
                'reject' => $this->rejectAction->handle($application, $actor, $note),
                default => throw new \InvalidArgumentException('Unsupported membership-claim review action.'),
            };

            return [
                'data' => [
                    'record' => [
                        'id' => $application->getKey(),
                        'status' => $application->status->value,
                    ],
                ],
            ];
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
            'granted_role' => $schema->string()->nullable()->enum(['owner', 'admin', 'editor']),
            'reviewer_note' => $schema->string()->nullable(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }
}
