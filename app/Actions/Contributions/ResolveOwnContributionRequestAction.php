<?php

namespace App\Actions\Contributions;

use App\Models\ContributionRequest;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveOwnContributionRequestAction
{
    use AsAction;

    public function handle(User $user, string $requestId): ContributionRequest
    {
        return $user->contributionRequests()
            ->with('entity')
            ->findOrFail($requestId);
    }
}
