<?php

declare(strict_types=1);

namespace App\Platform\Admin;

use App\Platform\Models\MorphModelResolver;
use Filament\Resources\Resource;

/**
 * Resolves registered admin resource classes and record URLs without importing
 * concrete feature Filament resources.
 */
final class AdminResourceLink
{
    /**
     * @return class-string<resource>|null
     */
    public static function forMorphAlias(string $morphAlias): ?string
    {
        $modelClass = MorphModelResolver::tryResolve($morphAlias);

        if ($modelClass === null) {
            return null;
        }

        return self::find(static fn (string $resource): bool => $resource::getModel() === $modelClass);
    }

    /**
     * @return class-string<resource>|null
     */
    public static function forSlug(string $slug): ?string
    {
        return self::find(static fn (string $resource): bool => $resource::getSlug() === $slug);
    }

    public static function to(
        string $morphAlias,
        string $page,
        string|int $recordId,
        ?string $panel = FilamentResourceRegistry::PANEL_ADMIN,
    ): ?string {
        return self::url(self::forMorphAlias($morphAlias), $page, $recordId, $panel);
    }

    public static function toSlug(
        string $slug,
        string $page,
        string|int $recordId,
        ?string $panel = FilamentResourceRegistry::PANEL_ADMIN,
    ): ?string {
        return self::url(self::forSlug($slug), $page, $recordId, $panel);
    }

    /**
     * @param  class-string<resource>|null  $resource
     */
    private static function url(
        ?string $resource,
        string $page,
        string|int $recordId,
        ?string $panel,
    ): ?string {
        if ($resource === null || ! $resource::hasPage($page)) {
            return null;
        }

        return $resource::getUrl($page, ['record' => $recordId], panel: $panel);
    }

    /**
     * @param  callable(class-string<resource>): bool  $matcher
     * @return class-string<resource>|null
     */
    private static function find(callable $matcher): ?string
    {
        foreach (app(FilamentResourceRegistry::class)->all() as $resource) {
            if ($matcher($resource)) {
                return $resource;
            }
        }

        return null;
    }
}
