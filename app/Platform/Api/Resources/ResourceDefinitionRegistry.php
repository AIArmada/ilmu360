<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use Filament\Resources\Resource;
use RuntimeException;

/**
 * Deterministic keyed registry for resource definitions.
 *
 * Filament may register several resource classes for the same model and
 * public key. Such collisions must be declared explicitly at application
 * composition time; unexpected collisions always fail.
 */
final class ResourceDefinitionRegistry
{
    /** @var array<string, array{0: class-string<resource>, 1: class-string<resource>}> */
    private array $intentionalCollisions = [];

    /** @var array<string, ResourceDefinition> First definition per surface key. */
    private array $definitions = [];

    /** @var list<ResourceDefinition> Every registered definition in discovery order. */
    private array $ordered = [];

    /** @var array<string, true> */
    private array $registered = [];

    /** @var array<string, ResourceWriter> */
    private array $writers = [];

    /** @var list<class-string> Resource classes in discovery order. */
    private array $order = [];

    /**
     * The application composition root declares exact, intentional duplicate
     * resource keys before feature providers register their definitions.
     *
     * @param  class-string<resource>  $firstResource
     * @param  class-string<resource>  $secondResource
     */
    public function allowCollision(
        ResourceSurface $surface,
        string $key,
        string $firstResource,
        string $secondResource,
    ): void {
        $surfaceKey = $this->key($surface, $key);

        if (isset($this->intentionalCollisions[$surfaceKey])) {
            throw new RuntimeException("Resource collision already declared for {$surfaceKey}.");
        }

        if (isset($this->definitions[$surfaceKey])) {
            throw new RuntimeException("Declare resource collisions before registering {$surfaceKey}.");
        }

        $this->intentionalCollisions[$surfaceKey] = [$firstResource, $secondResource];
    }

    /**
     * Set the public discovery order for Filament-backed resources. Definitions
     * registered by feature providers are sorted by this list; unknown classes
     * keep their registration order after the known ones.
     *
     * @param  list<class-string>  $resourceClasses
     */
    public function orderBy(array $resourceClasses): void
    {
        $this->order = array_values($resourceClasses);
    }

    public function register(ResourceDefinition $definition): void
    {
        $key = $this->key($definition->surface(), $definition->key());
        $identity = $key.'|'.$this->definitionClass($definition);

        if (isset($this->registered[$identity])) {
            throw new RuntimeException("Duplicate resource definition: {$key}");
        }

        if (isset($this->definitions[$key])) {
            $this->assertIntentionalCollision(
                $definition->surface(),
                $definition->key(),
                $this->definitions[$key],
                $definition,
            );
        }

        $this->registered[$identity] = true;
        $this->ordered[] = $definition;
        $this->definitions[$key] ??= $definition;

        if ($definition instanceof FilamentResourceDefinition
            && $definition->writer() instanceof ResourceWriter) {
            $writerKey = $this->key($definition->writer()->surface(), $definition->writer()->key());

            $this->writers[$writerKey] ??= $definition->writer();
        }
    }

    /**
     * @param  class-string  $resourceClass
     */
    public function hasForClass(ResourceSurface $surface, string $resourceClass): bool
    {
        return array_any($this->ordered, fn ($definition) => $definition->surface() === $surface
            && $definition instanceof FilamentBackedResourceDefinition
            && $definition->resourceClass() === $resourceClass);
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
        $definitions = array_values(array_filter(
            $this->ordered,
            static fn (ResourceDefinition $definition): bool => $definition->surface() === $surface,
        ));

        if ($this->order === []) {
            return $definitions;
        }

        $positions = array_flip($this->order);
        $position = static function (ResourceDefinition $definition) use ($positions): int {
            if (! $definition instanceof FilamentBackedResourceDefinition) {
                return PHP_INT_MAX;
            }

            return $positions[$definition->resourceClass()] ?? PHP_INT_MAX;
        };

        usort($definitions, static fn (ResourceDefinition $left, ResourceDefinition $right): int => $position($left) <=> $position($right));

        return $definitions;
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

    private function assertIntentionalCollision(
        ResourceSurface $surface,
        string $key,
        ResourceDefinition $existing,
        ResourceDefinition $incoming,
    ): void {
        $surfaceKey = $this->key($surface, $key);
        $existingClass = $this->definitionClass($existing);
        $incomingClass = $this->definitionClass($incoming);
        $declared = $this->declaredCollision($surface, $key);

        if ($declared === null) {
            throw new RuntimeException(
                "Conflicting resource definitions for {$surfaceKey}: {$existingClass} and {$incomingClass}. "
                .'Declare the exact intentional collision in the application composition root.',
            );
        }

        if (! $existing instanceof FilamentBackedResourceDefinition
            || ! $incoming instanceof FilamentBackedResourceDefinition) {
            throw new RuntimeException(
                "Stale intentional collision for {$surfaceKey}: expected Filament-backed "
                ."{$declared[0]} and {$declared[1]}, got {$existingClass} and {$incomingClass}.",
            );
        }

        $existingClass = $existing->resourceClass();
        $incomingClass = $incoming->resourceClass();

        if ($existingClass::getModel() !== $incomingClass::getModel()) {
            throw new RuntimeException(
                "Conflicting resource definitions for {$surfaceKey}: {$existingClass} and {$incomingClass} "
                ."resolve different models ({$existingClass::getModel()} and {$incomingClass::getModel()}).",
            );
        }

        if ($existingClass !== $declared[0] || $incomingClass !== $declared[1]) {
            throw new RuntimeException(
                "Stale intentional collision for {$surfaceKey}: expected {$declared[0]} and {$declared[1]}, "
                ."got {$existingClass} and {$incomingClass}.",
            );
        }
    }

    /**
     * @return array{0: class-string<resource>, 1: class-string<resource>}|null
     */
    private function declaredCollision(ResourceSurface $surface, string $key): ?array
    {
        return $this->intentionalCollisions[$this->key($surface, $key)] ?? null;
    }

    /**
     * @return class-string
     */
    private function definitionClass(ResourceDefinition $definition): string
    {
        return $definition instanceof FilamentBackedResourceDefinition
            ? $definition->resourceClass()
            : $definition::class;
    }

    private function key(ResourceSurface $surface, string $key): string
    {
        return $surface->value.':'.$key;
    }
}
