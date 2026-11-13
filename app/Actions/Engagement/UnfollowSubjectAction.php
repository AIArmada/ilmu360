<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Follow;
use App\Enums\ShareOutcomeType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Shared\Engagement\EngagementShareOutcomes;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root unfollow writer for the package-canonical follow rows.
 *
 * Locks the caller's active follow row inside the same transaction that
 * removes it, then reverses the follow share outcome through the
 * EngagementShareOutcomes contract only when a row was actually present.
 */
final readonly class UnfollowSubjectAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    public function handle(Institution|Person|Reference|Series $subject, User $user): UnfollowSubjectResult
    {
        return DB::transaction(function () use ($subject, $user): UnfollowSubjectResult {
            $follow = OwnerContext::withOwner(null, fn (): ?Follow => Follow::query()
                ->where('follower_type', $user->getMorphClass())
                ->where('follower_id', $user->getKey())
                ->where('followable_type', $subject->getMorphClass())
                ->where('followable_id', $subject->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first());

            $removed = $follow instanceof Follow;

            OwnerContext::withOwner(null, fn () => $this->engagement->unfollow($user, $subject));

            if ($removed && $follow instanceof Follow) {
                $this->shareOutcomes->reverseFollow(
                    type: $this->outcomeTypeFor($subject),
                    followId: (string) $follow->getKey(),
                    reason: 'unfollowed',
                    actor: $user,
                );
            }

            return new UnfollowSubjectResult(removed: $removed);
        });
    }

    private function outcomeTypeFor(Institution|Person|Reference|Series $subject): ShareOutcomeType
    {
        return match (true) {
            $subject instanceof Institution => ShareOutcomeType::InstitutionFollow,
            $subject instanceof Person => ShareOutcomeType::PersonFollow,
            $subject instanceof Reference => ShareOutcomeType::ReferenceFollow,
            $subject instanceof Series => ShareOutcomeType::SeriesFollow,
        };
    }
}
