<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\ApplyForMembershipAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\InvitationStatus;
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Submit an app-side membership application under the global owner scope.
 *
 * The package ApplyForMembershipAction remains the canonical application
 * writer; this action preserves the app-side subject lock, duplicate guards
 * and member/invitation checks around it.
 */
final readonly class SubmitMembershipApplicationAction
{
    use AsAction;

    public function __construct(
        private ApplyForMembershipAction $applyForMembershipAction,
        private MembershipDirectory $membershipDirectory,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $justification,
        array $meta = [],
    ): Model {
        return OwnerContext::withOwner(null, fn (): Model => DB::transaction(function () use ($accountId, $justification, $meta, $subjectId, $subjectType): Model {
            $subject = $this->subject($subjectType, $subjectId);
            $account = $this->account($accountId);

            $subject->newQuery()
                ->whereKey($subject->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertClaimIsAvailable($subject, $account, $subjectType, $subjectId, $accountId);

            $submittedApplication = $this->applyForMembershipAction->handle(
                $subject,
                $account,
                $justification,
                $meta,
            );

            return $this->applicationClass()::query()->findOrFail($submittedApplication->getKey());
        }));
    }

    private function assertClaimIsAvailable(
        Model $subject,
        Model $account,
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void {
        if ($subjectType === MemberSubjectType::Institution || $subjectType === MemberSubjectType::Person) {
            foreach ($this->membershipDirectory->members($subjectType, $subjectId) as $member) {
                if ($member->accountId === $accountId) {
                    throw new RuntimeException('membership_application_already_member');
                }
            }
        }

        $subjectMorphClass = (string) $subject->getMorphClass();

        if ($this->applicationClass()::query()
            ->where('subject_type', $subjectMorphClass)
            ->where('subject_id', $subjectId)
            ->where('applicant_id', $accountId)
            ->where('status', ApplicationStatus::Pending->value)
            ->exists()) {
            throw new RuntimeException('membership_application_duplicate_pending');
        }

        $email = $account->getAttribute('email');

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $invitationClass = MorphModelResolver::resolve('member_invitation');

        if ($invitationClass::query()
            ->where('subject_type', $subjectMorphClass)
            ->where('subject_id', $subjectId)
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', InvitationStatus::Pending->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists()) {
            throw new RuntimeException('membership_application_pending_invitation');
        }
    }

    private function subject(MemberSubjectType $subjectType, string $subjectId): Model
    {
        $modelClass = MorphModelResolver::resolve($subjectType->value);

        return $modelClass::query()->findOrFail($subjectId);
    }

    private function account(string $accountId): Model
    {
        $modelClass = MorphModelResolver::resolve('user');

        return $modelClass::query()->findOrFail($accountId);
    }

    /**
     * @return class-string<Model>
     */
    private function applicationClass(): string
    {
        return MorphModelResolver::resolve('membership_application');
    }
}
