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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root follow writer for the package-canonical follow rows.
 *
 * The package engagement tables have no application owner, so this named
 * action stays in the root Actions namespace. It owns the manager write and
 * records the follow share outcome through the EngagementShareOutcomes
 * contract; no caller should reproduce either.
 *
 * Callers own eligibility and the per-surface metadata shape: the follow API
 * sends `subject_id`/`subject_type`, while directory pages, cards and series
 * send `{type}_id`.
 */
final readonly class FollowSubjectAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Institution|Person|Reference|Series $subject,
        User $user,
        array $metadata,
        ?Request $request = null,
    ): FollowSubjectResult {
        return DB::transaction(function () use ($subject, $user, $metadata, $request): FollowSubjectResult {
            $follow = OwnerContext::withOwner(null, fn (): Follow => $this->engagement->follow($user, $subject));

            // Reactivations reuse the follow row: only rows created by this
            // call freeze a decision, so earlier relationships can never earn
            // later through a new cookie.
            $created = $follow->wasRecentlyCreated;

            if ($created) {
                $this->shareOutcomes->recordFollow(
                    type: $this->outcomeTypeFor($subject),
                    followId: (string) $follow->getKey(),
                    subject: $subject,
                    actor: $user,
                    request: $request,
                    metadata: $metadata,
                    occurredAt: $follow->created_at,
                );
            }

            return new FollowSubjectResult(follow: $follow, created: $created);
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
