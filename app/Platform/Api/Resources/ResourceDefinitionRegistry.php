<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use RuntimeException;

final class ResourceDefinitionRegistry
{
    /** @var array<string, ResourceDefinition> */
    private array $definitions = [];

    /** @var array<string, ResourceWriter> */
    private array $writers = [];

    public function register(ResourceDefinition $definition): void
    {
        $key = $this->key($definition->surface(), $definition->key());

        if (isset($this->definitions[$key])) {
            throw new RuntimeException("Duplicate resource definition: {$key}");
        }

        $this->definitions[$key] = $definition;
    }

    public function registerWriter(ResourceWriter $writer): void
    {
        $key = $this->key($writer->surface(), $writer->key());

        if (isset($this->writers[$key])) {
            throw new RuntimeException("Duplicate resource writer: {$key}");
        }

        $this->writers[$key] = $writer;
    }

    public function resolve(ResourceSurface $surface, string $key): ?ResourceDefinition
    {
        return $this->definitions[$this->key($surface, $key)] ?? null;
    }

    public function reader(ResourceSurface $surface, string $key): ?ResourceReader
    {
        $definition = $this->resolve($surface, $key);

        return $definition instanceof ResourceReader ? $definition : null;
    }

    public function writer(ResourceSurface $surface, string $key): ?ResourceWriter
    {
        return $this->writers[$this->key($surface, $key)] ?? null;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function all(ResourceSurface $surface): array
    {
        $definitions = array_filter(
            $this->definitions,
            static fn (ResourceDefinition $definition): bool => $definition->surface() === $surface,
        );

        return array_values($definitions);
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function accessible(ResourceSurface $surface, ResourceActor $actor): array
    {
        return array_values(array_filter(
            $this->all($surface),
            static fn (ResourceDefinition $definition): bool => $definition->canAccess($actor),
        ));
    }

    /**
     * @return list<ResourceWriter>
     */
    public function writable(ResourceSurface $surface): array
    {
        $writers = array_filter(
            $this->writers,
            static fn (ResourceWriter $writer): bool => $writer->surface() === $surface,
        );

        return array_values($writers);
    }

    private function key(ResourceSurface $surface, string $key): string
    {
        return $surface->value.':'.$key;
    }
}
