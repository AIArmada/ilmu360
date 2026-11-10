<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/**
 * Resolves a configured morph alias to its Eloquent model class.
 *
 * Relations that point at a resource owned by another capability resolve the
 * runtime target through its stable morph alias instead of importing that
 * feature's model class. The registered alias map remains the single source
 * of truth, so moving the model between capabilities does not require editing
 * every counterpart relation.
 */
final class MorphAliasModel
{
    /**
     * @return class-string<Model>
     */
    public static function classFor(string $alias): string
    {
        $class = Relation::getMorphedModel($alias);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException(sprintf(
                'The [%s] morph alias must resolve to an Eloquent model class.',
                $alias,
            ));
        }

        return $class;
    }
}
