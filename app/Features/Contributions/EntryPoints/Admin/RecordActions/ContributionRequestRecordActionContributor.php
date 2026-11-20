<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Admin\RecordActions;

use App\Features\Contributions\Api\Orm\AdminContributionRequestReview;
use App\Models\User;
use App\Platform\Api\Admin\Concerns\BuildsWorkflowActionDescriptors;
use App\Platform\Api\Admin\Contracts\RecordActionContributor;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes contribution request review follow-up actions to the admin
 * record action surface.
 */
final readonly class ContributionRequestRecordActionContributor implements RecordActionContributor
{
    use BuildsWorkflowActionDescriptors;

    public function __construct(
        private AdminContributionRequestReview $contributionRequestReviewService,
    ) {}

    public function supports(string $surface, string $resourceKey, Model $record): bool
    {
        return $surface === RecordActionContributor::SURFACE_ADMIN
            && $resourceKey === 'contribution-requests'
            && $record->getMorphClass() === 'contribution_request';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(string $surface, string $resourceKey, Model $record, ?User $actor = null): array
    {
        if (! $this->supports($surface, $resourceKey, $record)) {
            return [];
        }

        return $this->contributionRequestWorkflowActions($record, (string) $record->getRouteKey(), $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contributionRequestWorkflowActions(Model $record, string $recordKey, ?User $actor = null): array
    {
        if (! method_exists($record, 'isPending') || ! $record->isPending() || ! $this->contributionRequestReviewService->canReview($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->contributionRequestReviewService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_contribution_request_review_schema',
                label: 'Fetch contribution request review schema',
                description: 'Read the explicit review schema for this contribution request before choosing the action.',
                tool: 'admin-get-contribution-request-review-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'review_contribution_request',
                label: 'Review contribution request',
                description: 'Approve or reject this pending contribution request through the explicit review workflow.',
                tool: 'admin-review-contribution-request',
                recordKey: $recordKey,
                schemaTool: 'admin-get-contribution-request-review-schema',
                schemaActionKey: 'get_contribution_request_review_schema',
                schema: $schema,
            ),
        ];
    }
}
