<?php

namespace App\Actions\Contributions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final class EnsureNoPendingContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ResolvePendingContributionRequestAction $resolvePendingContributionRequestAction,
    ) {}

    public function handle(Model $entity): void
    {
        if ($this->resolvePendingContributionRequestAction->handle($entity) === null) {
            return;
        }

        throw ValidationException::withMessages([
            'data' => __('A contribution request for this record is already pending. Please wait for it to be reviewed before submitting another.'),
        ]);
    }
}
