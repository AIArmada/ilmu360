<?php

declare(strict_types=1);

namespace App\Actions\Contributions\Submission;

use App\Actions\Contributions\Queries\FindEntityPendingContributionRequestAction;
use App\Models\ContributionRequest;
use App\Shared\Contributions\ContributionRequestGuard;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class EnsureNoPendingContributionRequestAction implements ContributionRequestGuard
{
    use AsAction;

    public function __construct(
        private FindEntityPendingContributionRequestAction $findEntityPendingContributionRequestAction,
    ) {}

    public function handle(string $entityType, string $entityId): void
    {
        $this->ensureNoPending($entityType, $entityId);
    }

    public function ensureNoPending(string $entityType, string $entityId): void
    {
        if (! $this->findEntityPendingContributionRequestAction->handle($entityType, $entityId) instanceof ContributionRequest) {
            return;
        }

        throw ValidationException::withMessages([
            'data' => __('A contribution request for this record is already pending. Please wait for it to be reviewed before submitting another.'),
        ]);
    }
}
