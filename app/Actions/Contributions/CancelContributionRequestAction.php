<?php

namespace App\Actions\Contributions;

use App\Models\ContributionRequest;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CancelContributionRequestAction
{
    use AsAction;

    public function handle(ContributionRequest $request, User $proposer): ContributionRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Only pending contribution requests can be cancelled.');
        }

        if ((string) $request->proposer_id !== (string) $proposer->getKey()) {
            throw new RuntimeException('Only the original proposer can cancel this request.');
        }

        $request->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ])->save();

        return $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;
    }
}
