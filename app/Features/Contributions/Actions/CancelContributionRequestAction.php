<?php

namespace App\Features\Contributions\Actions;

use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CancelContributionRequestAction
{
    use AsAction;

    /**
     * @param  \App\Models\ContributionRequest  $request
     * @return \App\Models\ContributionRequest
     */
    public function handle(Model $request, Model $proposer): Model
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Only pending contribution requests can be cancelled.');
        }

        if ((string) $request->proposer_id !== (string) $proposer->getKey()) {
            throw new RuntimeException('Only the original proposer can cancel this request.');
        }

        $now = now();

        $request->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => $now,
            'last_state_change_at' => $now,
        ])->save();

        // Staged uploads never took effect, so drop them with the request.
        $request->clearMediaCollection('pending_media');

        return $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;
    }
}
