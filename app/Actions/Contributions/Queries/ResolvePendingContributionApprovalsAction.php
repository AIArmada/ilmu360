<?php

namespace App\Actions\Contributions\Queries;

use App\Enums\ContributionRequestStatus;
use App\Models\ContributionRequest;
use App\Models\User;
use App\Policies\ContributionRequestPolicy;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolvePendingContributionApprovalsAction
{
    use AsAction;

    public function __construct(
        protected ContributionRequestPolicy $contributionRequestPolicy,
    ) {}

    /**
     * @return Collection<int, ContributionRequest>
     */
    public function handle(User $user): Collection
    {
        return ContributionRequest::query()
            ->where('status', ContributionRequestStatus::Pending)
            ->with(['entity', 'proposer'])
            ->latest('created_at')
            ->get()
            ->filter(fn (ContributionRequest $request): bool => $this->contributionRequestPolicy->review($user, $request))
            ->values();
    }
}
