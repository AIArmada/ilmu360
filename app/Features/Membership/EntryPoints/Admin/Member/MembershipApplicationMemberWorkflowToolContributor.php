<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Admin\Member;

use App\Platform\Api\Member\Contracts\MemberWorkflowToolContributor;

/**
 * Publishes the member membership-application workflow tools to the member
 * manifest.
 */
final readonly class MembershipApplicationMemberWorkflowToolContributor implements MemberWorkflowToolContributor
{
    public function priority(): int
    {
        return 20;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            'list_membership_applications' => [
                'tool' => 'member-list-membership-applications',
            ],
            'submit_membership_application' => [
                'tool' => 'member-submit-membership-application',
            ],
            'cancel_membership_application' => [
                'tool' => 'member-cancel-membership-application',
            ],
        ];
    }
}
