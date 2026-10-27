<?php

namespace App\Actions\Contributions\Queries;

use App\Models\ContributionRequest;
use App\Models\User;
use App\Policies\ContributionRequestPolicy;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReviewableContributionRequestAction
{
    use AsAction;

    public function __construct(
        protected ContributionRequestPolicy $contributionRequestPolicy,
    ) {}

    public function handle(User $user, string $requestId): ContributionRequest
    {
        $request = ContributionRequest::query()->with('entity')->findOrFail($requestId);

        abort_unless($this->contributionRequestPolicy->review($user, $request), 403);

        return $request;
    }
}
