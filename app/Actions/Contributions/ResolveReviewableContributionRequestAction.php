<?php

namespace App\Actions\Contributions;

use App\Models\ContributionRequest;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReviewableContributionRequestAction
{
    use AsAction;

    public function __construct(
        protected CanReviewContributionRequestAction $canReviewContributionRequestAction,
    ) {}

    public function handle(User $user, string $requestId): ContributionRequest
    {
        $request = ContributionRequest::query()->with('entity')->findOrFail($requestId);

        abort_unless($this->canReviewContributionRequestAction->handle($user, $request), 403);

        return $request;
    }
}
