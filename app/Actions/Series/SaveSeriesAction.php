<?php

declare(strict_types=1);

namespace App\Actions\Series;

use App\Models\Series;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveSeriesAction
{
    use AsAction;

    public function __construct(
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Series $series = null): Series
    {
        $creating = ! $series instanceof Series;
        $series ??= new Series;

        $series->fill([
            'title' => $this->normalizeRequiredString($data['title'] ?? $series->title, 'Series'),
            'slug' => $this->normalizeRequiredString($data['slug'] ?? $series->slug, 'slug'),
            'description' => array_key_exists('description', $data)
                ? $this->normalizeOptionalString($data['description'])
                : $series->description,
            'visibility' => array_key_exists('visibility', $data)
                ? $this->normalizeVisibility($data['visibility'])
                : $this->normalizeVisibility($series->visibility ?? ($creating ? 'public' : null)),
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($creating ? true : (bool) $series->is_active),
        ]);

        $this->ensureUniqueSlug($series, (string) $series->slug);
        $series->save();

        $this->syncLanguages($series, $data);
        $this->syncMedia($series, $data);

        return $series->fresh(['languages', 'media']) ?? $series;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLanguages(Series $series, array $data): void
    {
        if (! array_key_exists('languages', $data)) {
            return;
        }

        $languageIds = [];

        foreach (is_array($data['languages']) ? $data['languages'] : [] as $languageId) {
            if (filter_var($languageId, FILTER_VALIDATE_INT) === false) {
                continue;
            }

            $languageIds[] = (int) $languageId;
        }

        $series->syncLanguages(array_values(array_unique($languageIds)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Series $series, array $data): void
    {
        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($series, 'cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($series, 'gallery');
        }

        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $series,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncMultiple(
            $series,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    private function ensureUniqueSlug(Series $series, string $slug): void
    {
        $query = Series::query()->where('slug', $slug);

        if ($series->exists) {
            $query->whereKeyNot($series->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('The slug has already been taken.'),
            ]);
        }
    }

    private function normalizeVisibility(mixed $value): string
    {
        $visibility = is_scalar($value) ? trim((string) $value) : '';

        if (! in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            throw ValidationException::withMessages([
                'visibility' => __('The selected series visibility is invalid.'),
            ]);
        }

        return $visibility;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => __('This field is required.'),
            ]);
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
