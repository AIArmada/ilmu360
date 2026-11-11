<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Features\Membership\Api\MembershipApplications;
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
class MemberCancelMembershipApplicationTool extends AbstractMemberTool
{
    protected string $name = 'member-cancel-membership-application';

    protected string $description = 'Use this when the authenticated Ahli/member needs to cancel a pending membership application they own. Do not use for cancelling applications owned by other members.';

    public function __construct(
        private readonly MembershipApplications $membershipApplications,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'application_id' => ['required', 'string'],
            ]);

            $application = $this->membershipApplications->cancelForApplicant(
                (string) $actor->getKey(),
                (string) $validated['application_id'],
            );

            abort_unless($application !== null, 404);

            return [
                'data' => [
                    'application' => [
                        'id' => $application->application->id,
                        'status' => $application->application->status,
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
            'application_id' => $schema->string()->required()->min(1),
        ];
    }
}
