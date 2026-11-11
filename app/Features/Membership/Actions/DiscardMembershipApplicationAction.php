<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Models\MorphModelResolver;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

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
        private MediaCollectionSync $mediaSyncService,
    ) {}

    public function handle(string $applicationId): void
    {
        OwnerContext::withOwner(null, function () use ($applicationId): void {
            $applicationClass = MorphModelResolver::resolve('membership_application');
            $application = $applicationClass::query()->findOrFail($applicationId);

            if (! $application instanceof HasMedia) {
                throw new RuntimeException('Membership applications must support evidence media.');
            }

            try {
                $this->mediaSyncService->clearCollection($application, 'evidence');
            } finally {
                $application->delete();
            }
        });
    }
}
