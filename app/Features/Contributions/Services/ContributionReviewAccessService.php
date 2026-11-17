<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\ContributionReviewAccess;
use App\Features\Contributions\Support\ContributionModel;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final readonly class ContributionReviewAccessService implements ContributionReviewAccess
{
    public function __construct(private AccountDirectory $accounts) {}

    public function pendingRequestIds(string $reviewerId): array
    {
        $actor = $this->reviewer($reviewerId);

        return ContributionModel::query('contribution_request')
            ->where('status', ContributionRequestStatus::Pending)
            ->with(['entity', 'proposer'])
            ->latest('created_at')
            ->get()
            ->filter(static fn (Model $request): bool => Gate::forUser($actor)->allows('review', $request))
            ->map(static fn (Model $request): string => (string) $request->getKey())
            ->values()
            ->all();
    }

    public function reviewableRequestId(string $reviewerId, string $requestId): string
    {
        $actor = $this->reviewer($reviewerId);
        $request = ContributionModel::query('contribution_request')->with('entity')->findOrFail($requestId);

        abort_unless(Gate::forUser($actor)->allows('review', $request), 403);

        return (string) $request->getKey();
    }

    private function reviewer(string $reviewerId): Authenticatable
    {
        $actor = $this->accounts->resolveNotifiable($reviewerId);

        abort_unless($actor instanceof Authenticatable, 404);

        return $actor;
    }
}
