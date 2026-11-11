<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction as PackageRejectMembershipApplicationAction;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reject a pending membership application under the global owner scope.
 *
 * The package RejectMembershipApplicationAction remains the canonical
 * membership_applications lifecycle writer; this action owns the app-side
 * owner-context and identifier resolution around it.
 */
final readonly class RejectMembershipApplicationAction
{
    use AsAction;

    public function __construct(
        private PackageRejectMembershipApplicationAction $rejectApplication,
    ) {}

    public function handle(
        string $applicationId,
        string $reviewerAccountId,
        ?string $note = null,
    ): Model {
        return OwnerContext::withOwner(null, function () use ($applicationId, $note, $reviewerAccountId): Model {
            $application = $this->applicationClass()::query()->findOrFail($applicationId);
            $reviewer = $this->accountClass()::query()->findOrFail($reviewerAccountId);

            $this->rejectApplication->handle($application, $reviewer, $note);

            return $this->applicationClass()::query()->findOrFail($applicationId);
        });
    }

    /**
     * @return class-string<Model>
     */
    private function applicationClass(): string
    {
        return MorphModelResolver::resolve('membership_application');
    }

    /**
     * @return class-string<Model>
     */
    private function accountClass(): string
    {
        return MorphModelResolver::resolve('user');
    }
}
