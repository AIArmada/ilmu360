<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\ApplyForMembershipAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\InvitationStatus;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\MembershipApplication;
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

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
        return OwnerContext::withOwner(null, function () use ($subject, $user, $justification, $meta): MembershipApplication {
            return DB::transaction(function () use ($subject, $user, $justification, $meta): MembershipApplication {
                $subject->newQuery()
                    ->whereKey($subject->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertClaimIsAvailable($subject, $user);

                $submittedApplication = $this->applyForMembershipAction->handle(
                    $subject,
                    $user,
                    $justification,
                    $meta,
                );

                return MembershipApplication::query()->findOrFail($submittedApplication->getKey());
            });
        });
    }

    private function assertClaimIsAvailable(Model $subject, Model $user): void
    {
        if (
            ($subject instanceof Institution || $subject instanceof Person)
            && $subject->members()->whereKey($user->getKey())->exists()
        ) {
            throw new RuntimeException('membership_claim_already_member');
        }

        $subjectType = (string) $subject->getMorphClass();
        $subjectId = (string) $subject->getKey();

        if (MembershipApplication::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('applicant_id', $user->getKey())
            ->where('status', ApplicationStatus::Pending->value)
            ->exists()) {
            throw new RuntimeException('membership_claim_duplicate_pending');
        }

        $email = $user->getAttribute('email');

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $pendingInvitationExists = MemberInvitation::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', InvitationStatus::Pending->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($pendingInvitationExists) {
            throw new RuntimeException('membership_claim_pending_invitation');
        }
    }
}
