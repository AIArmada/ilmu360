<?php

declare(strict_types=1);

namespace App\Platform\Admin;

use Filament\Resources\Resource;
use InvalidArgumentException;

/**
 * Collects Filament resources registered by feature providers so panel
 * composition never imports concrete feature entrypoints.
 */
final class FilamentResourceRegistry
{
    public const string PANEL_ADMIN = 'admin';

    public const string PANEL_AHLI = 'ahli';

    /** @var array<string, list<class-string<resource>>> */
    private array $resources = [];

    /**
     * @param  class-string<resource>  $resource
     */
    public function register(string $resource, string $panel = self::PANEL_ADMIN): void
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered Filament resource must extend %s; %s given.',
                Resource::class,
                $resource,
            ));
        }

        if (! in_array($resource, $this->resources[$panel] ?? [], true)) {
            $this->resources[$panel][] = $resource;
        }
    }

    /**
     * @return list<class-string<resource>>
     */
    public function all(string $panel = self::PANEL_ADMIN): array
    {
        return $this->resources[$panel] ?? [];
    }
}
