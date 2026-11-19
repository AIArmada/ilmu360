<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Contracts\Followable;
use AIArmada\Engagement\Models\Follow;
use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Engagement-owned follow writer for the package-canonical follow rows.
 *
 * The package owns follow storage; the feature owns orchestration. It owns the manager write and
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
        Model&Followable $subject,
        Model&CanInteract&Authenticatable&AccountActor $user,
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
                    subject: ShareSubject::for(
                        type: $subject->getMorphClass(),
                        id: (string) $subject->getKey(),
                        title: match ($subject->getMorphClass()) {
                            'person' => (string) data_get($subject, 'formatted_name'),
                            'institution' => (string) data_get($subject, 'name'),
                            default => (string) data_get($subject, 'title'),
                        },
                    ),
                    actor: $user,
                    request: $request instanceof Request ? ShareRequestContext::fromRequest($request) : null,
                    metadata: $metadata,
                    occurredAt: $follow->created_at,
                );
            }

            return new FollowSubjectResult(follow: $follow, created: $created);
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
