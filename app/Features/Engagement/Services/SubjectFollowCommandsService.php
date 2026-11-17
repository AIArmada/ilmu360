<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Engagement\Actions\FollowSubjectAction;
use App\Features\Engagement\Actions\UnfollowSubjectAction;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Engagement\Support\EngagementModels;

final readonly class SubjectFollowCommandsService implements SubjectFollowCommands
{
    public function __construct(
        private FollowSubjectAction $followAction,
        private UnfollowSubjectAction $unfollowAction,
    ) {}

    public function follow(string $subjectType, string $subjectId, string $actorId, array $metadata): array
    {
        $result = $this->followAction->handle(
            EngagementModels::followable($subjectType, $subjectId),
            EngagementModels::actor($actorId),
            $metadata,
            request(),
        );

        return ['follow_id' => (string) $result->follow->getKey(), 'created' => $result->created];
    }

    public function unfollow(string $subjectType, string $subjectId, string $actorId): bool
    {
        return $this->unfollowAction->handle(
            EngagementModels::followable($subjectType, $subjectId),
            EngagementModels::actor($actorId),
        )->removed;
    }
}
