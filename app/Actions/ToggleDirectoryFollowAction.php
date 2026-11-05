<?php

namespace App\Actions;

use App\Enums\ShareOutcomeType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class ToggleDirectoryFollowAction
{
    use AsAction;

    public function handle(string $type, string $id, User $user): ?bool
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        [$subject, $outcomeType] = match ($type) {
            'institution' => [Institution::query()->whereIn('status', ['verified', 'pending'])->whereKey($id)->first(), ShareOutcomeType::InstitutionFollow],
            'person' => [Person::query()->speakers()->whereIn('status', ['verified', 'pending'])->whereKey($id)->first(), ShareOutcomeType::PersonFollow],
            'reference' => [Reference::query()->active()->whereKey($id)->first(), ShareOutcomeType::ReferenceFollow],
            default => [null, null],
        };

        if ($subject === null || $outcomeType === null) {
            return null;
        }

        if ($user->isFollowing($subject)) {
            $user->unfollow($subject);

            return $user->isFollowing($subject);
        }

        $follow = $user->follow($subject);

        if ($follow->wasRecentlyCreated) {
            app(ShareTrackingService::class)->recordOutcome(
                type: $outcomeType,
                businessKey: ShareOutcomeBusinessKey::follow($outcomeType, (string) $follow->getKey()),
                subject: $subject,
                actor: $user,
                request: request(),
                metadata: ["{$type}_id" => $subject->id],
                occurredAt: $follow->created_at,
            );
        }

        return $user->isFollowing($subject);
    }
}
