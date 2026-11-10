<?php

namespace App\Actions\Slugs;

use App\Models\SlugRedirect;
use App\Platform\Models\SlugOrUuidResolver;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ResolvePublicSlugAction
{
    public function __construct(
        private PublicSlugRegistry $publicSlugRegistry,
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    /**
     * @return array{model: Model, redirect_to: string|null, slug_redirect: SlugRedirect|null}
     */
    public function handle(string $parameter, string $value): array
    {
        $resource = $this->publicSlugRegistry->resourceForParameter($parameter);
        $resolvedModel = $this->resolveCurrentModel($resource, $value);

        if ($resolvedModel instanceof Model) {
            return [
                'model' => $resolvedModel,
                'redirect_to' => null,
                'slug_redirect' => null,
            ];
        }

        $sourcePath = $this->publicSlugRegistry->pathForParameter($parameter, $value);

        if ($sourcePath !== null) {
            $slugRedirect = SlugRedirect::query()
                ->with('redirectable')
                ->where('source_path', $sourcePath)
                ->first();

            $redirectable = $slugRedirect?->redirectable;
            $redirectTo = $redirectable instanceof Model
                ? $this->publicSlugRegistry->pathForModel($redirectable)
                : null;

            if ($slugRedirect instanceof SlugRedirect && $redirectable instanceof Model && $redirectTo !== null) {
                return [
                    'model' => $redirectable,
                    'redirect_to' => $redirectTo,
                    'slug_redirect' => $slugRedirect,
                ];
            }
        }

        $this->throwNotFound($resource, $value);
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

    private function throwNotFound(?PublicSlugResource $resource, string $value): never
    {
        $modelClass = $resource instanceof PublicSlugResource ? $resource->modelClass : Model::class;

        throw (new ModelNotFoundException)->setModel($modelClass, [$value]);
    }
}
