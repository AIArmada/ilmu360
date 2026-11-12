<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Actions\ApproveContributionRequestAction;
use App\Features\Contributions\Actions\CancelContributionRequestAction;
use App\Features\Contributions\Actions\DeleteContributionRequestsAction;
use App\Features\Contributions\Actions\RejectContributionRequestAction;
use App\Features\Contributions\Actions\SubmitContributionCreateRequestAction;
use App\Features\Contributions\Actions\SubmitContributionUpdateRequestAction;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Support\ContributionModel;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolves scalar identities to accounts, morph entities and contribution
 * requests, then delegates every lifecycle transition to its named action.
 */
final readonly class ContributionRequestLifecycleService implements ContributionRequestLifecycle
{
    public function __construct(
        private AccountDirectory $accounts,
        private SubmitContributionCreateRequestAction $submitCreateRequest,
        private SubmitContributionUpdateRequestAction $submitUpdateRequest,
        private ApproveContributionRequestAction $approveRequest,
        private RejectContributionRequestAction $rejectRequest,
        private CancelContributionRequestAction $cancelRequest,
        private DeleteContributionRequestsAction $deleteRequests,
    ) {}

    public function submitCreate(
        ContributionSubjectType $subjectType,
        string $proposerId,
        array $proposedData,
        ?string $proposerNote = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): string {
        $request = $this->submitCreateRequest->handle(
            $subjectType,
            $this->account($proposerId),
            $proposedData,
            $proposerNote,
            $this->optionalEntity($entityType, $entityId),
        );

        return (string) $request->getKey();
    }

    public function submitUpdate(
        string $entityType,
        string $entityId,
        string $proposerId,
        array $proposedData,
        ?string $proposerNote = null,
    ): string {
        $request = $this->submitUpdateRequest->handle(
            $this->requiredEntity($entityType, $entityId),
            $this->account($proposerId),
            $proposedData,
            $proposerNote,
        );

        return (string) $request->getKey();
    }

    public function approve(string $requestId, string $reviewerId, ?string $reviewerNote = null): void
    {
        $this->approveRequest->handle(
            $this->request($requestId),
            $this->account($reviewerId),
            $reviewerNote,
        );
    }

    public function reject(string $requestId, string $reviewerId, string $reasonCode, ?string $reviewerNote = null): void
    {
        $this->rejectRequest->handle(
            $this->request($requestId),
            $this->account($reviewerId),
            $reasonCode,
            $reviewerNote,
        );
    }

    public function cancel(string $requestId, string $proposerId): void
    {
        $this->cancelRequest->handle(
            $this->request($requestId),
            $this->account($proposerId),
        );
    }

    public function delete(array $requestIds): int
    {
        return $this->deleteRequests->handle($requestIds);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function account(string $accountId): Model
    {
        $account = $this->accounts->resolveNotifiable($accountId);

        if (! $account instanceof Model) {
            throw new RuntimeException("Contribution actor [{$accountId}] was not found.");
        }

        return $account;
    }

    /**
     * @return \App\Models\ContributionRequest
     */
    private function request(string $requestId): Model
    {
        return ContributionModel::query('contribution_request')->findOrFail($requestId);
    }

    private function optionalEntity(?string $entityType, ?string $entityId): ?Model
    {
        if ($entityType === null && $entityId === null) {
            return null;
        }

        if ($entityType === null || $entityType === '' || $entityId === null || $entityId === '') {
            throw new RuntimeException('A contribution entity type and identifier are required together.');
        }

        return MorphModelResolver::resolve($entityType)::query()->findOrFail($entityId);
    }

    private function requiredEntity(string $entityType, string $entityId): Model
    {
        return $this->optionalEntity($entityType, $entityId)
            ?? throw new RuntimeException('A contribution entity type and identifier are required.');
    }
}
