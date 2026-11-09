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
    /** @var list<class-string<\Filament\Resources\Resource>> */
    private array $resources = [];

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public function register(string $resource): void
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered Filament resource must extend %s; %s given.',
                Resource::class,
                $resource,
            ));
        }

        if (! in_array($resource, $this->resources, true)) {
            $this->resources[] = $resource;
        }
    }

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public function all(): array
    {
        return $this->resources;
    }
}
