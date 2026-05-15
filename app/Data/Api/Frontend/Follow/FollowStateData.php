<?php

namespace App\Data\Api\Frontend\Follow;

use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\User;
use Spatie\LaravelData\Data;

class FollowStateData extends Data
{
    public function __construct(
        public string $type,
        public string $id,
        public string $slug,
        public bool $is_following,
    ) {}

    public static function fromModel(Institution|Speaker|Reference|Series $record, User $user): self
    {
        return new self(
            type: strtolower(class_basename($record)),
            id: (string) $record->getKey(),
            slug: (string) $record->slug,
            is_following: $user->isFollowing($record),
        );
    }
}
