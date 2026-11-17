<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Engagement\Actions\FollowSubjectAction;
use App\Features\Engagement\Actions\UnfollowSubjectAction;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class SubjectFollowCommandsService implements SubjectFollowCommands
{
    public function __construct(
        private FollowSubjectAction $followAction,
        private UnfollowSubjectAction $unfollowAction,
    ) {}

    public function follow(string $subjectType, string $subjectId, string $actorId, array $metadata): array
    {
        $result = $this->followAction->handle(
            $this->subject($subjectType, $subjectId),
            User::query()->findOrFail($actorId),
            $metadata,
            request(),
        );

        return ['follow_id' => (string) $result->follow->getKey(), 'created' => $result->created];
    }

    public function unfollow(string $subjectType, string $subjectId, string $actorId): bool
    {
        return $this->unfollowAction->handle(
            $this->subject($subjectType, $subjectId),
            User::query()->findOrFail($actorId),
        )->removed;
    }

    /** @return Institution|Person|Reference|Series */
    private function subject(string $type, string $id): Model
    {
        return match ($type) {
            'institution' => Institution::query()->findOrFail($id),
            'person' => Person::query()->findOrFail($id),
            'reference' => Reference::query()->findOrFail($id),
            'series' => Series::query()->findOrFail($id),
            default => throw new InvalidArgumentException("Unsupported followable subject [{$type}]."),
        };
    }
}
