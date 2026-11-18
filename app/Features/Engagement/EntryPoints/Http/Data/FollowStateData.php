<?php

namespace App\Features\Engagement\EntryPoints\Http\Data;

use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Features\Events\Models\Series;
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

    public static function fromModel(Institution|Person|Reference|Series $record, User $user): self
    {
        return new self(
            type: strtolower(class_basename($record)),
            id: (string) $record->getKey(),
            slug: (string) $record->slug,
            is_following: $user->isFollowing($record),
        );
    }
}
