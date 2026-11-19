<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Admin\Member;

use App\Platform\Api\Member\Contracts\MemberWorkflowToolContributor;

/**
 * Publishes the member contribution-request workflow tools to the member
 * manifest.
 */
final readonly class ContributionRequestMemberWorkflowToolContributor implements MemberWorkflowToolContributor
{
    public function priority(): int
    {
        return 10;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            'list_contribution_requests' => [
                'tool' => 'member-list-contribution-requests',
            ],
            'approve_contribution_request' => [
                'tool' => 'member-approve-contribution-request',
            ],
            'reject_contribution_request' => [
                'tool' => 'member-reject-contribution-request',
            ],
            'cancel_contribution_request' => [
                'tool' => 'member-cancel-contribution-request',
            ],
        ];
    }
}
