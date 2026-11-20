<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the authenticated identity carried by a {@see ResourceActor} into
 * the concrete application user used for gate and policy checks.
 */
final class ResourceActorResolver
{
    public function current(ResourceSurface $surface = ResourceSurface::Admin): ResourceActor
    {
        return $this->fromUser(auth()->user(), $surface);
    }

    public function fromUser(?Authenticatable $user, ResourceSurface $surface = ResourceSurface::Admin): ResourceActor
    {
        return new ResourceActor(
            $user instanceof User ? (string) $user->getKey() : '',
            $surface,
        );
    }

    public function user(ResourceActor $actor): ?User
    {
        if ($actor->accountId === '') {
            return null;
        }

        $current = auth()->user();

        if ($current instanceof User && (string) $current->getKey() === $actor->accountId) {
            return $current;
        }

        return User::query()->find($actor->accountId);
    }
}
