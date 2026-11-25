<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Mcp\Tools;

use App\Features\Membership\Api\MembershipApplicationFormRules;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use App\Platform\Mcp\McpAuthenticatedUserResolver;
use App\Platform\Mcp\Tools\Admin\AbstractAdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
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
class AdminReviewMembershipApplicationTool extends AbstractAdminTool
{
    #[\Override]
    protected string $name = 'admin-review-membership-application';

    #[\Override]
    protected string $description = 'Use this when you need to approve or reject a pending membership application. Fetch the review schema first with admin-get-membership-application-review-schema. Do not use for reading application details; use admin-get-record for that.';

    public function __construct(
        private readonly MembershipApplications $membershipApplications,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);
            $requiresGrantedRole = ($request->all()['action'] ?? null) === 'approve';

            $record = $request->validate([
                'record_key' => ['required', 'string'],
            ]);
            $application = $this->membershipApplications->find((string) $record['record_key']);
            abort_unless($application instanceof \App\Features\Membership\Api\MembershipApplicationDetail, 404);

            $validated = $this->validateArguments($request, [
                'record_key' => ['required', 'string'],
                'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
                'granted_role' => [
                    Rule::requiredIf($requiresGrantedRole),
                    'nullable',
                    'string',
                    Rule::in(array_keys(MembershipApplicationFormRules::roleOptions($application->application->subjectType))),
                ],
                'reviewer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $note = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

            $detail = match ((string) $validated['action']) {
                'approve' => $this->membershipApplications->approve(
                    $application->application->id,
                    (string) $actor->getKey(),
                    (string) ($validated['granted_role'] ?? ''),
                    $note,
                ),
                'reject' => $this->membershipApplications->reject(
                    $application->application->id,
                    (string) $actor->getKey(),
                    $note,
                ),
                default => throw new \LogicException('Validated membership-application action is unsupported.'),
            };

            return [
                'data' => [
                    'record' => [
                        'id' => $detail->application->id,
                        'status' => $detail->application->status,
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
            'granted_role' => $schema->string()->nullable()->enum(array_keys(MembershipApplicationFormRules::roleOptions(MemberSubjectType::Person))),
            'reviewer_note' => $schema->string()->nullable(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }
}
