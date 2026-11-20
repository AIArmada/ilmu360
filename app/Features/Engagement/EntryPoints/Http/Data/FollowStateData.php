<?php

namespace App\Features\Engagement\EntryPoints\Http\Data;

use App\Shared\Directory\FollowableSubject;
use Spatie\LaravelData\Data;

class FollowStateData extends Data
{
    public function __construct(
        public string $type,
        public string $id,
        public string $slug,
        public bool $is_following,
    ) {}

    public static function fromSubject(FollowableSubject $subject, bool $isFollowing): self
    {
        return new self(
            type: $subject->type,
            id: $subject->id,
            slug: $subject->slug,
            is_following: $isFollowing,
        );
    }
}
