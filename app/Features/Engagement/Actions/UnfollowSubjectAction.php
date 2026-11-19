<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Contracts\Followable;
use AIArmada\Engagement\Models\Follow;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Shared\Engagement\EngagementShareOutcomes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Engagement-owned unfollow writer for the package-canonical follow rows.
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

    public function handle(Model&Followable $subject, Model&CanInteract&Authenticatable $user): UnfollowSubjectResult
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

    private function outcomeTypeFor(Model&Followable $subject): ShareOutcomeType
    {
        return match ($subject->getMorphClass()) {
            'institution' => ShareOutcomeType::InstitutionFollow,
            'person' => ShareOutcomeType::PersonFollow,
            'reference' => ShareOutcomeType::ReferenceFollow,
            'series' => ShareOutcomeType::SeriesFollow,
            default => throw new InvalidArgumentException('Unsupported follow subject.'),
        };
    }
}
