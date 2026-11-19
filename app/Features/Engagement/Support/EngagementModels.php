<?php

declare(strict_types=1);

namespace App\Features\Engagement\Support;

use AIArmada\Engagement\Contracts\Bookmarkable;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\Followable;
use AIArmada\Engagement\Contracts\Respondable;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Auth\AccountActor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

/** Resolve active morph identities without importing another feature's private models. */
final class EngagementModels
{
    public static function actor(string $actorId): Model&CanInteract&Authenticatable&AccountActor
    {
        $actor = MorphModelResolver::resolve('user')::query()->findOrFail($actorId);

        if (! $actor instanceof CanInteract || ! $actor instanceof Authenticatable || ! $actor instanceof AccountActor) {
            throw new RuntimeException('The user morph must support authenticated engagement.');
        }

        return $actor;
    }

    public static function event(string $eventId): Model&Bookmarkable&Respondable
    {
        $event = MorphModelResolver::resolve('event')::query()->findOrFail($eventId);

        if (! $event instanceof Bookmarkable || ! $event instanceof Respondable) {
            throw new RuntimeException('The event morph must support engagement.');
        }

        return $event;
    }

    public static function followable(string $type, string $id): Model&Followable
    {
        if (! in_array($type, ['institution', 'person', 'reference', 'series'], true)) {
            throw new InvalidArgumentException("Unsupported followable subject [{$type}].");
        }

        $subject = MorphModelResolver::resolve($type)::query()->findOrFail($id);

        if (! $subject instanceof Followable) {
            throw new RuntimeException("The [{$type}] morph must support following.");
        }

        return $subject;
    }
}
