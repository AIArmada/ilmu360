<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Models\MembershipApplication;
use App\Support\Media\ModelMediaSyncService;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Remove an application that could not be completed with its required evidence.
 *
 * A failed evidence upload must not leave a pending claim that moderators
 * cannot verify. Media is cleared before the application row is removed.
 */
final readonly class DiscardMembershipApplicationAction
{
    use AsAction;

    public function __construct(
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    public function handle(MembershipApplication $application): void
    {
        try {
            $this->mediaSyncService->clearCollection($application, 'evidence');
        } finally {
            $application->delete();
        }
    }
}
