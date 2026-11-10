<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Canonical list of public slug surfaces. Concrete models are supplied by root
 * wiring so this Platform contract never imports a feature-owned class.
 */
final class PublicSlugRegistry
{
    /** @var list<PublicSlugResource> */
    private array $resources = [];

    /** @var array<string, PublicSlugResource> */
    private array $byParameter = [];

    /** @var array<class-string<Model>, PublicSlugResource> */
    private array $byModelClass = [];

    /**
     * @param  list<PublicSlugResource>  $resources
     */
    public function __construct(array $resources)
    {
        foreach ($resources as $resource) {
            $this->resources[] = $resource;
            $this->byParameter[$resource->parameter] = $resource;
            $this->byModelClass[$resource->modelClass] = $resource;
        }
    }

    /**
     * @return list<PublicSlugResource>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * @return list<string>
     */
    public function parameters(): array
    {
        return array_map(
            static fn (PublicSlugResource $resource): string => $resource->parameter,
            $this->resources,
        );
    }

    public function resourceForParameter(string $parameter): ?PublicSlugResource
    {
        return $this->byParameter[$parameter] ?? null;
    }

    public function resourceForModel(Model $model): ?PublicSlugResource
    {
        return $this->byModelClass[$model::class] ?? null;
    }

    /**
     * Resolve either the route parameter, the model class, or a registered morph
     * alias to its public slug resource.
     */
    public function resourceForType(string $type): ?PublicSlugResource
    {
        $type = ltrim(trim($type), '\\');

        if ($type === '') {
            return null;
        }

        $resource = $this->byParameter[$type] ?? $this->byModelClass[$type] ?? null;

        if ($resource instanceof PublicSlugResource) {
            return $resource;
        }

        $modelClass = Relation::getMorphedModel($type);

        return $modelClass === null ? null : ($this->byModelClass[$modelClass] ?? null);
    }

    public function parameterForModel(Model $model): ?string
    {
        return $this->resourceForModel($model)?->parameter;
    }

    public function pathForParameter(string $parameter, string $slug): ?string
    {
        return $this->resourceForParameter($parameter)?->pathForSlug($slug);
    }

    public function pathForModel(Model $model): ?string
    {
        $slug = $this->normalizedSlug($model->getAttribute('slug'));

        if ($slug === null) {
            return null;
        }

        return $this->resourceForModel($model)?->pathForSlug($slug);
    }

    public function labelFor(Model $model): string
    {
        $resource = $this->resourceForModel($model);

        return $resource instanceof PublicSlugResource
            ? $resource->labelFor($model)
            : class_basename($model::class).' #'.$model->getKey();
    }

    private function normalizedSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
