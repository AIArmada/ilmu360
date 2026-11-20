<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

/**
 * Shared collaborators injected into every Filament-backed resource definition.
 *
 * Grouping them keeps owner adapters to a two-argument constructor while the
 * Platform engine stays singleton-scoped.
 */
final readonly class ResourceDefinitionContext
{
    public function __construct(
        public ResourceActorResolver $actors,
        public EloquentResourceQuery $queries,
        public EloquentResourceSerializer $serializer,
        public ResourceIntrospection $introspection,
        public AdminResourceMetadataBuilder $metadata,
        public ResourceDefinitionDirectory $directory,
        public EloquentResourceWriterSupport $writerSupport,
    ) {}
}
