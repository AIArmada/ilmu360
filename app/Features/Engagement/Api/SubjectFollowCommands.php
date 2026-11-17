<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/** Follow/unfollow application workflows for supported public subject morphs. */
interface SubjectFollowCommands
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{follow_id: string, created: bool}
     */
    public function follow(string $subjectType, string $subjectId, string $actorId, array $metadata): array;

    public function unfollow(string $subjectType, string $subjectId, string $actorId): bool;
}
