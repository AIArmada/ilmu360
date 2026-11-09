<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Normalizes stored polymorphic types to Laravel morph aliases.
 *
 * Legacy rows may hold fully qualified model class names. Writes always use the
 * configured morph alias, and resolvers compare normalized values so both
 * storage forms resolve during the transition window.
 */
final class MorphAlias
{
    public static function of(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return $type;
        }

        $class = ltrim($type, '\\');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return $type;
        }

        $model = new $class;

        return $model->getMorphClass();
    }
}
