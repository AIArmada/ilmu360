<?php

declare(strict_types=1);

namespace App\Features\Directory\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Runtime model access for Directory actions while the models remain in the
 * legacy root namespace. Resolution goes through the registered morph aliases
 * so the feature never imports another capability's private model class.
 */
final class DirectoryModel
{
    public static function institution(): string
    {
        return MorphModelResolver::resolve('institution');
    }

    public static function institutionName(): string
    {
        return MorphModelResolver::resolve('institution_name');
    }

    public static function importExclusion(): string
    {
        return MorphModelResolver::resolve('institution_import_exclusion');
    }

    public static function donationChannel(): string
    {
        return MorphModelResolver::resolve('donation_channel');
    }

    public static function person(): string
    {
        return MorphModelResolver::resolve('person');
    }

    public static function personName(): string
    {
        return MorphModelResolver::resolve('person_name');
    }

    public static function affiliation(): string
    {
        return MorphModelResolver::resolve('affiliation');
    }

    public static function reference(): string
    {
        return MorphModelResolver::resolve('reference');
    }

    /**
     * Reference records are global; bypass the owner scope for reads that do
     * not have request context (observers, jobs, console).
     *
     * @return Builder<Model>
     */
    public static function referenceQuery(): Builder
    {
        return self::query('reference')->withoutOwnerScope();
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
