<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use App\Support\Rewards\PublishedRewardProgramVersionResolver;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

final class EnrollRewardProgramMemberAction
{
    use AsAction;

    public function handle(RewardProgram $program, User $user, string $surface = 'member-portal'): ?RewardEnrollment
    {
        Gate::forUser($user)->authorize('enroll', $program);

        $version = app(PublishedRewardProgramVersionResolver::class)->resolve($program);

        if ($version === null) {
            return null;
        }

        $memberType = $user->getMorphClass();
        $memberId = (string) $user->getKey();
        $wasEnrolled = RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $memberType)
            ->where('member_id', $memberId)
            ->where('status', 'active')
            ->exists();

        $enrollment = EnrollRewardMemberAction::run(
            $program,
            'user',
            $memberId,
            $version,
            'member-portal',
        );

        EvaluatePreclaimShareOutcomesAction::run($program, $user, $version);

        if (! $wasEnrolled) {
            app(SignalRecorder::class)->record(
                eventName: 'rewards.program_enrolled',
                eventCategory: 'engagement',
                actorId: (string) $user->getKey(),
                properties: [
                    'program_id' => (string) $program->getKey(),
                    'program_family' => $program->family_key,
                    'enrollment_id' => (string) $enrollment->getKey(),
                    'surface' => $surface,
                ],
                request: request(),
            );
        }

        return $enrollment;
    }
}
