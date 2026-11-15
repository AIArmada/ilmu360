<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final class PrepareSlugRedirectInputAction
{
    use AsAction;

    public function __construct(
        private readonly PublicSlugRegistry $publicSlugRegistry,
    ) {}

    /**
     * @return array{redirectable_type: string|null, redirectable_id: string|null, source_slug: string|null, source_path: string|null, destination_slug: string|null, destination_path: string|null}
     */
    public function handle(
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug,
        ?string $exceptRedirectId = null,
    ): array {
        $resource = $this->redirectableResource($redirectableType);
        $type = $resource instanceof PublicSlugResource
            ? (new $resource->modelClass)->getMorphClass()
            : null;
        $redirectable = $resource instanceof PublicSlugResource && filled($redirectableId)
            ? $resource->modelClass::query()->find($redirectableId)
            : null;
        $sourceSlug = $this->normalizeSlug($sourceSlug);
        $errors = [];

        if ($type === null) {
            $errors['redirectable_type'] = 'Select a supported subject type.';
        }

        if (! $redirectable instanceof Model) {
            $errors['redirectable_id'] = 'Select a valid subject.';
        }

        if ($sourceSlug === null) {
            $errors['source_slug'] = 'Enter the old source slug to redirect.';
        }

        $destinationSlug = $redirectable instanceof Model
            ? $this->normalizeSlug($redirectable->getAttribute('slug'))
            : null;
        $destinationPath = $redirectable instanceof Model
            ? $this->publicSlugRegistry->pathForModel($redirectable)
            : null;
        $sourcePath = $resource instanceof PublicSlugResource && $sourceSlug !== null
            ? $resource->pathForSlug($sourceSlug)
            : null;

        if ($redirectable instanceof Model && $destinationSlug === null) {
            $errors['redirectable_id'] = 'The selected subject does not have a current canonical slug.';
        }

        if ($redirectable instanceof Model && $destinationPath === null) {
            $errors['redirectable_id'] = 'The selected subject does not expose a canonical public URL.';
        }

        if ($sourceSlug !== null && $destinationSlug !== null && $sourceSlug === $destinationSlug) {
            $errors['source_slug'] = 'The source slug must differ from the selected subject\'s current canonical slug.';
        }

        if ($sourcePath !== null && $destinationPath !== null && $sourcePath === $destinationPath) {
            $errors['source_slug'] = 'The source slug resolves to the same public path as the destination.';
        }

        if ($sourcePath !== null) {
            $query = SlugRedirect::query()->where('source_path', $sourcePath);

            if ($exceptRedirectId !== null) {
                $query->whereKeyNot($exceptRedirectId);
            }

            if ($query->exists()) {
                $errors['source_slug'] = 'Another redirect already uses this public source path.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'redirectable_type' => $type,
            'redirectable_id' => (string) $redirectable?->getKey(),
            'source_slug' => $sourceSlug,
            'source_path' => $sourcePath,
            'destination_slug' => $destinationSlug,
            'destination_path' => $destinationPath,
        ];
    }

    private function redirectableResource(?string $type): ?PublicSlugResource
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        return $this->publicSlugRegistry->resourceForType(trim($type));
    }

    private function normalizeSlug(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $slug = Str::lower(trim($value));

        return $slug !== '' ? $slug : null;
    }
}
