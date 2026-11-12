<?php

namespace App\Actions\Contributions\Queries;

use App\Models\ContributionRequest;
use App\Models\User;
use App\Features\Contributions\Api\ContributionRequestReader;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveOwnContributionRequestAction
{
    use AsAction;

    public function handle(User $user, string $requestId): ContributionRequest
    {
        $ownedRequestId = app(ContributionRequestReader::class)->findRequestIdForProposer(
            (string) $user->getKey(),
            $requestId,
        );

        return ContributionRequest::query()
            ->with('entity')
            ->findOrFail($ownedRequestId);
    }
}
