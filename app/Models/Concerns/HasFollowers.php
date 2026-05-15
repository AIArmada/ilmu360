<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasFollowers
{
    /**
     * @return MorphToMany<User, $this>
     */
    public function followers(): MorphToMany
    {
        return $this->morphToMany(User::class, 'followable', 'followings')
            ->withTimestamps();
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($this->relationLoaded('followers')) {
            return $this->followers->contains('id', $user->id);
        }

        return $this->followers()->where('user_id', $user->id)->exists();
    }

    public function followersCount(): int
    {
        return $this->followers()->count();
    }
}
