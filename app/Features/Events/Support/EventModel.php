<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Runtime model access for Events actions while the models remain in the
 * legacy root namespace. Resolution goes through the registered morph aliases
 * so the feature never imports another capability's private model class.
 */
final class EventModel
{
    public static function event(): string
    {
        return MorphModelResolver::resolve('event');
    }

    public static function series(): string
    {
        return MorphModelResolver::resolve('series');
    }

    public static function occurrence(): string
    {
        return MorphModelResolver::resolve('event_occurrence');
    }

    public static function session(): string
    {
        return MorphModelResolver::resolve('event_session');
    }

    public static function submission(): string
    {
        return MorphModelResolver::resolve('event_submission');
    }

    public static function keyPerson(): string
    {
        return MorphModelResolver::resolve('event_key_person');
    }

    public static function location(): string
    {
        return MorphModelResolver::resolve('event_location');
    }

    public static function registration(): string
    {
        return MorphModelResolver::resolve('registration');
    }

    /**
     * @return Builder<Model>
     */
    public static function query(string $alias): Builder
    {
        return MorphModelResolver::resolve($alias)::query();
    }

    public static function new(string $alias): Model
    {
        $class = MorphModelResolver::resolve($alias);

        return new $class;
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
}
