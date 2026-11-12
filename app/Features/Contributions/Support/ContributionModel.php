<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Runtime model access for Contributions while the models remain in the
 * legacy root namespace. Resolution goes through the registered morph aliases
 * so the feature never imports its private model classes directly.
 */
final class ContributionModel
{
    /**
     * @return class-string<Model>
     */
    public static function modelClass(): string
    {
        return MorphModelResolver::resolve('contribution_request');
    }

    /**
     * @return Builder<Model>
     */
    public static function query(string $alias): Builder
    {
        return MorphModelResolver::resolve($alias)::query();
    }

    public static function assert(Model $model, string $alias): Model
    {
        if ($model->getMorphClass() !== $alias) {
            throw new RuntimeException(sprintf(
                'Expected a [%s] record, got [%s].',
                $alias,
                $model->getMorphClass(),
            ));
        }

        return $model;
    }

    public static function isEvent(mixed $entity): bool
    {
        return self::isMorphedAs($entity, 'event');
    }

    public static function isInstitution(mixed $entity): bool
    {
        return self::isMorphedAs($entity, 'institution');
    }

    public static function isPerson(mixed $entity): bool
    {
        return self::isMorphedAs($entity, 'person');
    }

    public static function isReference(mixed $entity): bool
    {
        return self::isMorphedAs($entity, 'reference');
    }

    private static function isMorphedAs(mixed $entity, string $alias): bool
    {
        return $entity instanceof Model && is_a($entity, MorphModelResolver::resolve($alias));
    }
}
