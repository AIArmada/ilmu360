<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\ApplyForMembershipAction;
use App\Models\MembershipApplication;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SubmitMembershipApplicationAction
{
    use AsAction;

    public function __construct(
        private ApplyForMembershipAction $applyForMembershipAction,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(Model $subject, Model $user, string $justification, array $meta = []): MembershipApplication
    {
        $submittedApplication = $this->applyForMembershipAction->handle(
            $subject,
            $user,
            $justification,
            $meta,
        );

        return OwnerContext::withOwner(null, fn (): MembershipApplication => MembershipApplication::query()
            ->findOrFail($submittedApplication->getKey()));
    }
}
