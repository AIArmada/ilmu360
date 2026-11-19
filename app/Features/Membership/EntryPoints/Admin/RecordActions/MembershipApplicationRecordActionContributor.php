<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Admin\RecordActions;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplications;
use App\Models\User;
use App\Platform\Api\Admin\Contracts\RecordActionContributor;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes membership application review follow-up actions to the admin
 * record action surface.
 */
final readonly class MembershipApplicationRecordActionContributor implements RecordActionContributor
{
    public function __construct(
        private MembershipApplications $membershipApplications,
    ) {}

    public function supports(string $surface, string $resourceKey, Model $record): bool
    {
        return $surface === RecordActionContributor::SURFACE_ADMIN
            && $resourceKey === 'membership-applications';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(string $surface, string $resourceKey, Model $record, ?User $actor = null): array
    {
        if (! $this->supports($surface, $resourceKey, $record)) {
            return [];
        }

        $recordKey = (string) $record->getRouteKey();
        $application = $this->membershipApplications->find($recordKey);

        if (! $application instanceof MembershipApplicationDetail) {
            return [];
        }

        return $this->membershipApplicationWorkflowActions($application->application->status, $recordKey, $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipApplicationWorkflowActions(string $status, string $recordKey, ?User $actor = null): array
    {
        if ($status !== ApplicationStatus::Pending->value || (! $actor instanceof User || ! $actor->hasAnyRole(['super_admin', 'admin', 'moderator']))) {
            return [];
        }

        return [
            [
                'key' => 'get_membership_application_review_schema',
                'label' => 'Fetch membership application review schema',
                'category' => 'workflow_schema',
                'description' => 'Read the explicit review schema for this membership application before choosing the action.',
                'tool' => 'admin-get-membership-application-review-schema',
                'arguments' => ['record_key' => $recordKey],
            ],
            [
                'key' => 'review_membership_application',
                'label' => 'Review membership application',
                'category' => 'workflow',
                'description' => 'Approve or reject this pending membership application through the explicit review workflow.',
                'tool' => 'admin-review-membership-application',
                'arguments' => ['record_key' => $recordKey, 'action' => 'approve', 'granted_role' => null, 'reviewer_note' => null],
                'requires' => ['get_membership_application_review_schema'],
                'schema_tool' => 'admin-get-membership-application-review-schema',
                'schema_tool_arguments' => ['record_key' => $recordKey],
                'schema_action_key' => 'get_membership_application_review_schema',
            ],
        ];
    }
}
