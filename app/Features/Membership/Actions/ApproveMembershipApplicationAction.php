<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\ApproveMembershipApplicationAction as PackageApproveMembershipApplicationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Approve a pending membership application under the global owner scope.
 *
 * The package ApproveMembershipApplicationAction remains the canonical
 * membership_applications lifecycle writer; this action owns the app-side
 * owner-context and identifier resolution around it.
 */
final readonly class ApproveMembershipApplicationAction
{
    use AsAction;

    public function __construct(
        private PackageApproveMembershipApplicationAction $approveApplication,
    ) {}

    public function handle(
        string $applicationId,
        string $reviewerAccountId,
        string $grantedRole,
        ?string $note = null,
    ): Model {
        return OwnerContext::withOwner(null, function () use ($applicationId, $grantedRole, $note, $reviewerAccountId): Model {
            $application = $this->applicationClass()::query()->findOrFail($applicationId);
            $reviewer = $this->accountClass()::query()->findOrFail($reviewerAccountId);

            $this->approveApplication->handle(
                $application,
                $reviewer,
                MemberRole::tryFrom($grantedRole) ?? MemberRole::Editor,
                $note,
            );

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
