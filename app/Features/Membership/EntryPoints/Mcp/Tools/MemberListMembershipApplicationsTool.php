<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Mcp\Tools;

use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplications;
use App\Platform\Mcp\Tools\Member\AbstractMemberTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemberListMembershipApplicationsTool extends AbstractMemberTool
{
    public function __construct(private readonly MembershipApplications $membershipApplications) {}

    #[\Override]
    protected string $name = 'member-list-membership-applications';

    #[\Override]
    protected string $description = 'Use this when you need to list the authenticated member\'s membership applications. Do not use for admin-level membership application management.';

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);
            $this->validateArguments($request, []);
            $accountId = (string) $actor->getKey();

            return [
                'data' => array_map(
                    static fn (MembershipApplicationDetail $application): array => $application->applicantPayload($accountId),
                    $this->membershipApplications->forApplicant($accountId),
                ),
            ];
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
