<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions\Enrollment;

use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\LeaveRewardProgramAction;
use AIArmada\Rewards\Actions\ReacceptRewardTermsAction;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Features\Rewards\Api\MemberEnrollmentSnapshot;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Rewards\PublishedProgramVersions;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Member enrollment, leave and reacceptance.
 *
 * The package `EnrollRewardMemberAction`, `LeaveRewardProgramAction` and
 * `ReacceptRewardTermsAction` stay the canonical writers and keep their
 * argument order. This action resolves the member through
 * `Shared\Auth\AccountDirectory`, authorizes with `Gate::forUser()` against
 * the existing `enroll`/`leave` policies, resolves the published version
 * through `Shared\Rewards\PublishedProgramVersions`, then runs the preclaim
 * backfill after the package write. The first-active
 * `rewards.program_enrolled` signal is recorded only when the caller asks for
 * it, preserving the API-vs-web telemetry asymmetry.
 */
final readonly class EnrollRewardProgramMemberAction implements MemberEnrollment
{
    public function __construct(
        private AccountDirectory $accounts,
        private PublishedProgramVersions $publishedVersions,
        private EvaluatePreclaimShareOutcomesAction $preclaimAction,
    ) {}

    public function enroll(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $surface = 'member-portal',
        bool $recordSignal = true,
        ?string $acceptedVia = null,
    ): ?MemberEnrollmentSnapshot {
        $program = RewardProgram::query()->findOrFail($programId);

        $this->authorizeMember($memberId, 'enroll', $program);

        $version = $versionId !== null
            ? $this->explicitVersion($program, $versionId)
            : $this->publishedVersion($programId);

        if (! $version instanceof RewardProgramVersion) {
            return null;
        }

        $wasEnrolled = RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $recipientType)
            ->where('member_id', $memberId)
            ->where('status', 'active')
            ->exists();

        $acceptedVia ??= $recordSignal ? 'member-portal' : 'api';

        $enrollment = EnrollRewardMemberAction::run(
            $program,
            $recipientType,
            $memberId,
            $version,
            $acceptedVia,
        );

        $this->preclaimAction->preclaim(
            (string) $program->getKey(),
            $memberId,
            $recipientType,
            (string) $version->getKey(),
        );

        if ($recordSignal && ! $wasEnrolled) {
            app(SignalRecorder::class)->record(
                eventName: 'rewards.program_enrolled',
                eventCategory: 'engagement',
                actorId: $memberId,
                properties: [
                    'program_id' => (string) $program->getKey(),
                    'program_family' => $program->family_key,
                    'enrollment_id' => (string) $enrollment->getKey(),
                    'surface' => $surface,
                ],
                request: request(),
            );
        }

        return new MemberEnrollmentSnapshot(
            enrollmentId: (string) $enrollment->getKey(),
            acceptanceId: null,
            programId: (string) $program->getKey(),
            memberId: $memberId,
            status: $enrollment->status->value,
        );
    }

    public function leave(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $reason = null,
    ): void {
        $program = RewardProgram::query()->findOrFail($programId);

        $this->authorizeMember($memberId, 'leave', $program);

        $enrollment = $this->enrollmentFor($programId, $memberId, $recipientType);

        LeaveRewardProgramAction::run($enrollment, $reason);
    }

    public function reaccept(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $acceptedVia = 'member-portal',
    ): ?MemberEnrollmentSnapshot {
        $program = RewardProgram::query()->findOrFail($programId);

        $this->authorizeMember($memberId, 'leave', $program);

        $enrollment = $this->enrollmentFor($programId, $memberId, $recipientType);

        $version = $versionId !== null
            ? $this->explicitVersion($program, $versionId)
            : $this->publishedVersion($programId);

        if (! $version instanceof RewardProgramVersion) {
            return null;
        }

        $acceptance = ReacceptRewardTermsAction::run($enrollment, $version, $acceptedVia);

        $this->preclaimAction->preclaim(
            (string) $program->getKey(),
            $memberId,
            $recipientType,
            (string) $version->getKey(),
        );

        return new MemberEnrollmentSnapshot(
            enrollmentId: (string) $enrollment->getKey(),
            acceptanceId: (string) $acceptance->getKey(),
            programId: (string) $program->getKey(),
            memberId: $memberId,
            status: $enrollment->status->value,
            materialTermsHash: (string) $acceptance->material_terms_hash,
        );
    }

    public function preclaim(
        string $programId,
        string $memberId,
        string $recipientType,
        string $versionId,
    ): void {
        $this->preclaimAction->preclaim($programId, $memberId, $recipientType, $versionId);
    }

    private function authorizeMember(string $memberId, string $ability, RewardProgram $program): void
    {
        $member = $this->accounts->resolveNotifiable($memberId);

        if (! $member instanceof Authenticatable) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        Gate::forUser($member)->authorize($ability, $program);
    }

    private function enrollmentFor(string $programId, string $memberId, string $recipientType): RewardEnrollment
    {
        /** @var RewardEnrollment $enrollment */
        $enrollment = RewardEnrollment::query()
            ->where('program_id', $programId)
            ->where('member_type', $recipientType)
            ->where('member_id', $memberId)
            ->firstOrFail();

        return $enrollment;
    }

    private function explicitVersion(RewardProgram $program, string $versionId): RewardProgramVersion
    {
        /** @var RewardProgramVersion $version */
        $version = RewardProgramVersion::query()
            ->where('program_id', $program->getKey())
            ->whereKey($versionId)
            ->firstOrFail();

        return $version;
    }

    private function publishedVersion(string $programId): ?RewardProgramVersion
    {
        $versionId = $this->publishedVersions->publishedVersionId($programId);

        if ($versionId === null) {
            return null;
        }

        /** @var RewardProgramVersion|null $version */
        $version = RewardProgramVersion::query()
            ->where('program_id', $programId)
            ->find($versionId);

        return $version;
    }
}
