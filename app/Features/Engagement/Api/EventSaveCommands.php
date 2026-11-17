<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/** Authenticated event bookmarks; feature owns orchestration, package owns rows. */
interface EventSaveCommands
{
    /** @return array{bookmark_id: string|null, created: bool} */
    public function save(string $eventId, string $actorId): array;

    public function unsave(string $eventId, string $actorId): bool;
}
