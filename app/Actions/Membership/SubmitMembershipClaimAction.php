<?php

namespace App\Actions\Membership;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Support\MembershipSubjectGuard;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\MembershipClaim;
use App\Models\Speaker;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SubmitMembershipClaimAction
{
    use AsAction;

    public function handle(Institution|Speaker $subject, User $claimant, string $justification): MembershipClaim
    {
        $subjectType = MemberSubjectType::forSubject($subject);

        if (! $subjectType->isClaimable()) {
            throw new RuntimeException('membership_claim_out_of_scope');
        }

        return OwnerContext::withOwner(null, function () use ($subject, $claimant, $justification, $subjectType): MembershipClaim {
            app(MembershipSubjectGuard::class)->validate($subject);

            if ($subject->members()->whereKey($claimant->getKey())->exists()) {
                throw new RuntimeException('membership_claim_already_member');
            }

            $normalizedEmail = is_string($claimant->email) ? mb_strtolower(trim($claimant->email)) : null;

            if ($normalizedEmail !== null && $normalizedEmail !== '') {
                $hasPendingInvitation = MemberInvitation::query()
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subject->getKey())
                    ->where('email', $normalizedEmail)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->exists();

                if ($hasPendingInvitation) {
                    throw new RuntimeException('membership_claim_pending_invitation');
                }
            }

            $hasPendingClaim = MembershipClaim::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subject->getKey())
                ->where('applicant_id', $claimant->getKey())
                ->where('status', ApplicationStatus::Pending)
                ->exists();

            if ($hasPendingClaim) {
                throw new RuntimeException('membership_claim_duplicate_pending');
            }

            return MembershipClaim::create([
                'subject_type' => $subjectType,
                'subject_id' => $subject->getKey(),
                'applicant_id' => $claimant->getKey(),
                'status' => ApplicationStatus::Pending,
                'justification' => trim($justification),
            ]);
        });
    }
}
