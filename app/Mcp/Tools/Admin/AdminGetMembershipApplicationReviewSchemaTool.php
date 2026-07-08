<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Membership\MembershipApplicationPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminGetMembershipApplicationReviewSchemaTool extends AbstractAdminTool
{
    protected string $name = 'admin-get-membership-application-review-schema';

    protected string $description = 'Use this when you need the review schema for a membership application before submitting an approve or reject decision. Returns available actions, required fields, and conditional rules.';

    public function __construct(
        private AdminResourceRegistry $registry,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'record_key' => ['required', 'string'],
            ]);

            /** @var MembershipApplication $application */
            $application = $this->registry->resolveRecord(MembershipApplicationResource::class, (string) $validated['record_key']);

            return [
                'data' => [
                    'schema' => [
                        'action' => 'review_membership_application',
                        'defaults' => [
                            'action' => 'approve',
                            'granted_role' => null,
                            'reviewer_note' => null,
                        ],
                        'fields' => [
                            [
                                'name' => 'action',
                                'type' => 'string',
                                'required' => true,
                                'default' => 'approve',
                                'allowed_values' => ['approve', 'reject'],
                            ],
                            [
                                'name' => 'granted_role',
                                'type' => 'string',
                                'required' => false,
                                'allowed_values' => array_keys(MembershipApplicationPresenter::approvalRoleOptions($application)),
                            ],
                            [
                                'name' => 'reviewer_note',
                                'type' => 'string',
                                'required' => false,
                                'max_length' => 2000,
                            ],
                        ],
                        'conditional_rules' => [
                            [
                                'field' => 'granted_role',
                                'required_when' => ['action' => ['approve']],
                            ],
                        ],
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
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }
}
