<?php

namespace App\Actions\Slugs;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\SlugRedirect;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Models\SlugOrUuidResolver;
use App\Support\Slugs\PublicSlugPathResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ResolvePublicSlugAction
{
    public function __construct(
        private PublicSlugPathResolver $publicSlugPathResolver,
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    /**
     * @return array{model: Model, redirect_to: string|null, slug_redirect: SlugRedirect|null}
     */
    public function handle(string $parameter, string $value): array
    {
        $resolvedModel = $this->resolveCurrentModel($parameter, $value);

        if ($resolvedModel instanceof Model) {
            return [
                'model' => $resolvedModel,
                'redirect_to' => null,
                'slug_redirect' => null,
            ];
        }

        $sourcePath = $this->publicSlugPathResolver->pathForParameter($parameter, $value);

        if ($sourcePath !== null) {
            $slugRedirect = SlugRedirect::query()
                ->with('redirectable')
                ->where('source_path', $sourcePath)
                ->first();

            $redirectable = $slugRedirect?->redirectable;
            $redirectTo = $redirectable instanceof Model
                ? $this->publicSlugPathResolver->pathForModel($redirectable)
                : null;

            if ($slugRedirect instanceof SlugRedirect && $redirectable instanceof Model && $redirectTo !== null) {
                return [
                    'model' => $redirectable,
                    'redirect_to' => $redirectTo,
                    'slug_redirect' => $slugRedirect,
                ];
            }
        }

        $this->throwNotFound($parameter, $value);
    }

    private function resolveCurrentModel(string $parameter, string $value): ?Model
    {
        return match ($parameter) {
            'event' => $this->resolveBySlugOrUuid(new Event, $value),
            'institution' => $this->resolveBySlugOrUuid(new Institution, $value),
            'speaker' => $this->resolveBySlugOrUuid(new Speaker, $value),
            'venue' => $this->resolveBySlugOrUuid(new Venue, $value),
            'reference' => $this->resolveBySlugOrUuid(new Reference, $value),
            default => null,
        };
    }

    private function resolveBySlugOrUuid(Model $model, string $value): ?Model
    {
        return $this->slugOrUuidResolver->first(
            $model->newQuery(),
            $model->qualifyColumn('slug'),
            $value,
        );
    }

    private function throwNotFound(string $parameter, string $value): never
    {
        $modelClass = match ($parameter) {
            'event' => Event::class,
            'institution' => Institution::class,
            'speaker' => Speaker::class,
            'venue' => Venue::class,
            'reference' => Reference::class,
            default => Model::class,
        };

        throw (new ModelNotFoundException)->setModel($modelClass, [$value]);
    }
}
