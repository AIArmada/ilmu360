<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Contracts\ContributionRequestGuard;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class EnsureNoPendingContributionRequestAction implements ContributionRequestGuard
{
    use AsAction;

    public function __construct(
        private ContributionRequestReader $contributionRequestReader,
    ) {}

    public function handle(string $entityType, string $entityId): void
    {
        $this->ensureNoPending($entityType, $entityId);
    }

    public function ensureNoPending(string $entityType, string $entityId): void
    {
        if ($this->contributionRequestReader->pendingRequestIdForEntity($entityType, $entityId) === null) {
            return;
        }

        throw ValidationException::withMessages([
            'data' => __('A contribution request for this record is already pending. Please wait for it to be reviewed before submitting another.'),
        ]);
    }
}
