<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/**
 * Resolves a feature-owned model through its stable morph alias.
 *
 * Cross-feature read paths and relations use this instead of importing another
 * feature's private model class. Writes still belong to the owning feature.
 */
final class MorphModelResolver
{
    /**
     * @return class-string<Model>
     */
    public static function resolve(string $alias): string
    {
        $model = Relation::getMorphedModel($alias);

        if ($model === null || ! is_a($model, Model::class, true)) {
            throw new RuntimeException("The [{$alias}] morph alias must resolve to an Eloquent model.");
        }

        return $model;
    }

    /**
     * @return class-string<Model>|null
     */
    public static function tryResolve(string $alias): ?string
    {
        $model = Relation::getMorphedModel($alias);

        return $model !== null && is_a($model, Model::class, true) ? $model : null;
    }
}
