<?php

declare(strict_types=1);

namespace App\Actions\Contributions\Submission;

use App\Actions\Contributions\Queries\FindEntityPendingContributionRequestAction;
use App\Models\ContributionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class EnsureNoPendingContributionRequestAction
{
    use AsAction;

    public function __construct(
        private FindEntityPendingContributionRequestAction $findEntityPendingContributionRequestAction,
    ) {}

    public function handle(Model $entity): void
    {
        if (! $this->findEntityPendingContributionRequestAction->handle($entity) instanceof ContributionRequest) {
            return;
        }

        throw ValidationException::withMessages([
            'data' => __('A contribution request for this record is already pending. Please wait for it to be reviewed before submitting another.'),
        ]);
    }
}
