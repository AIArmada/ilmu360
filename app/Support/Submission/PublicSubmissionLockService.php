<?php

namespace App\Support\Submission;

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\AuthzScope;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final readonly class PublicSubmissionLockService
{
    public function __construct(
        private MemberRoleScopes $memberRoleScopes,
    ) {}

    public function institutionEligibility(Institution $institution): SubmissionLockEligibilityResult
    {
        return $this->resolveEligibility(
            $institution->members()->get(),
            $this->memberRoleScopes->institution(),
            __('Tiada ahli institusi yang didaftarkan.'),
            __('Tiada ahli institusi dengan peranan owner/admin.'),
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        );
    }

    public function speakerEligibility(Speaker $speaker): SubmissionLockEligibilityResult
    {
        return $this->resolveEligibility(
            $speaker->members()->get(),
            $this->memberRoleScopes->speaker(),
            __('Tiada ahli penceramah yang didaftarkan.'),
            __('Tiada ahli penceramah dengan peranan owner/admin.'),
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        );
    }

    public function lockInstitution(Institution $institution, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $eligibility = $this->institutionEligibility($institution);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $institution->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actor->getKey(),
        ])->save();

        Cache::forget('submit_institutions');
    }

    public function lockSpeaker(Speaker $speaker, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $eligibility = $this->speakerEligibility($speaker);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $speaker->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actor->getKey(),
        ])->save();

        Cache::forget('submit_speakers');
    }

    public function unlockInstitution(Institution $institution, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_institutions');
    }

    public function unlockSpeaker(Speaker $speaker, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $speaker->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_speakers');
    }

    public function ensureInstitutionUnlockedIfIneligible(Institution $institution): bool
    {
        if ($institution->allow_public_event_submission) {
            return false;
        }

        $eligibility = $this->institutionEligibility($institution);

        if ($eligibility->eligible) {
            return false;
        }

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_institutions');

        return true;
    }

    public function ensureSpeakerUnlockedIfIneligible(Speaker $speaker): bool
    {
        if ($speaker->allow_public_event_submission) {
            return false;
        }

        $eligibility = $this->speakerEligibility($speaker);

        if ($eligibility->eligible) {
            return false;
        }

        $speaker->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_speakers');

        return true;
    }

    /**
     * @return array{institutions_reopened: int, speakers_reopened: int}
     */
    public function sweepLockedEntities(): array
    {
        $institutionsReopened = 0;

        Institution::query()
            ->where('allow_public_event_submission', false)
            ->each(function (Institution $institution) use (&$institutionsReopened): void {
                if ($this->ensureInstitutionUnlockedIfIneligible($institution)) {
                    $institutionsReopened++;
                }
            });

        $speakersReopened = 0;

        Speaker::query()
            ->where('allow_public_event_submission', false)
            ->each(function (Speaker $speaker) use (&$speakersReopened): void {
                if ($this->ensureSpeakerUnlockedIfIneligible($speaker)) {
                    $speakersReopened++;
                }
            });

        return [
            'institutions_reopened' => $institutionsReopened,
            'speakers_reopened' => $speakersReopened,
        ];
    }

    public function syncForUser(User $user): void
    {
        /** @var list<string> $institutionIds */
        $institutionIds = $user->institutions()
            ->where('allow_public_event_submission', false)
            ->pluck('institutions.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($institutionIds !== []) {
            Institution::query()
                ->whereIn('id', $institutionIds)
                ->each(fn (Institution $institution): bool => $this->ensureInstitutionUnlockedIfIneligible($institution));
        }

        /** @var list<string> $speakerIds */
        $speakerIds = $user->speakers()
            ->where('allow_public_event_submission', false)
            ->pluck('speakers.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($speakerIds !== []) {
            Speaker::query()
                ->whereIn('id', $speakerIds)
                ->each(fn (Speaker $speaker): bool => $this->ensureSpeakerUnlockedIfIneligible($speaker));
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function resolveEligibility(
        Collection $members,
        AuthzScope $scope,
        string $noMembersReason,
        string $missingRoleReason,
        string $missingVerifiedPhoneReason,
    ): SubmissionLockEligibilityResult {
        if ($members->isEmpty()) {
            return SubmissionLockEligibilityResult::ineligible([$noMembersReason]);
        }

        $hasOwnerOrAdminMember = false;

        foreach ($members as $member) {
            if (! $member instanceof User) {
                continue;
            }

            $hasRole = Authz::withScope(
                $scope,
                fn (): bool => $member->hasAnyRole(['owner', 'admin']),
                $member,
            );

            if (! $hasRole) {
                continue;
            }

            $hasOwnerOrAdminMember = true;

            if ($member->phone_verified_at !== null) {
                return SubmissionLockEligibilityResult::eligible();
            }
        }

        if (! $hasOwnerOrAdminMember) {
            return SubmissionLockEligibilityResult::ineligible([$missingRoleReason]);
        }

        return SubmissionLockEligibilityResult::ineligible([$missingVerifiedPhoneReason]);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGlobalLockPermission(User $actor): void
    {
        if ($actor->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return;
        }

        throw new AuthorizationException(__('Anda tidak dibenarkan mengunci penghantaran awam.'));
    }
}
