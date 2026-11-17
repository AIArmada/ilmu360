<?php

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Api\PublicSlugLookup;
use App\Features\SlugRedirects\Api\ResolvedPublicSlug;
use App\Features\SlugRedirects\Api\SlugRedirectLookup;
use App\Features\SlugRedirects\Api\SlugRedirectMatch;
use App\Platform\Models\SlugOrUuidResolver;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ResolvePublicSlugAction implements PublicSlugLookup
{
    public function __construct(
        private PublicSlugRegistry $publicSlugRegistry,
        private SlugOrUuidResolver $slugOrUuidResolver,
        private SlugRedirectLookup $slugRedirectLookup,
    ) {}

    public function resolve(string $parameter, string $value): ResolvedPublicSlug
    {
        $resource = $this->publicSlugRegistry->resourceForParameter($parameter);
        $resolvedModel = $this->resolveCurrentModel($resource, $value);

        if ($resolvedModel instanceof Model) {
            return $this->resultFor($resolvedModel);
        }

        $sourcePath = $this->publicSlugRegistry->pathForParameter($parameter, $value);

        if ($sourcePath !== null) {
            $slugRedirect = $this->slugRedirectLookup->findBySourcePath($sourcePath);
            $redirectable = $slugRedirect instanceof SlugRedirectMatch
                ? $this->resolveRedirectable($slugRedirect)
                : null;
            $redirectTo = $redirectable instanceof Model
                ? $this->publicSlugRegistry->pathForModel($redirectable)
                : null;

            if ($slugRedirect instanceof SlugRedirectMatch && $redirectable instanceof Model && $redirectTo !== null) {
                return $this->resultFor($redirectable, $redirectTo, $slugRedirect->id);
            }
        }

        $this->throwNotFound($resource, $value);
    }

    private function resultFor(Model $model, ?string $redirectTo = null, ?string $slugRedirectId = null): ResolvedPublicSlug
    {
        return new ResolvedPublicSlug(
            modelType: $model->getMorphClass(),
            modelId: (string) $model->getKey(),
            redirectTo: $redirectTo,
            slugRedirectId: $slugRedirectId,
        );
    }

    private function resolveCurrentModel(?PublicSlugResource $resource, string $value): ?Model
    {
        if (! $resource instanceof PublicSlugResource) {
            return null;
        }

        $model = new $resource->modelClass;

        return $this->slugOrUuidResolver->first(
            $model->newQuery(),
            $model->qualifyColumn('slug'),
            $value,
        );
    }

    private function resolveRedirectable(SlugRedirectMatch $slugRedirect): ?Model
    {
        $resource = $this->publicSlugRegistry->resourceForType($slugRedirect->redirectableType);

        if (! $resource instanceof PublicSlugResource) {
            return null;
        }

        $redirectable = $resource->modelClass::query()->find($slugRedirect->redirectableId);

        return $redirectable instanceof Model ? $redirectable : null;
    }

    private function throwNotFound(?PublicSlugResource $resource, string $value): never
    {
        $modelClass = $resource instanceof PublicSlugResource ? $resource->modelClass : Model::class;

        throw (new ModelNotFoundException)->setModel($modelClass, [$value]);
    }
}
