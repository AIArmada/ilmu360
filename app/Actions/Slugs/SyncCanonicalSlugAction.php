<?php

namespace App\Actions\Slugs;

use App\Actions\Slugs\Concerns\NormalizesComparableStrings;
use Illuminate\Database\Eloquent\Model;

final readonly class SyncCanonicalSlugAction
{
    use NormalizesComparableStrings;

    public function __construct(
        private SyncSlugRedirectAction $syncSlugRedirectAction,
    ) {}

    public function persist(Model $model, string $slug): bool
    {
        $currentSlug = $this->currentSlug($model);
        $normalizedCurrentSlug = $this->normalizeComparableString($currentSlug);
        $normalizedTargetSlug = $this->normalizeComparableString($slug);

        if ($this->normalizeComparableString($model->getAttribute('slug')) !== $normalizedCurrentSlug) {
            $model->forceFill([
                'slug' => $currentSlug,
            ]);
            $model->syncOriginal();
        }

        if ($normalizedCurrentSlug === $normalizedTargetSlug) {
            return false;
        }

        $previousSlug = $normalizedCurrentSlug;
        $modelClass = $model::class;

        $modelClass::withoutTimestamps(function () use ($model, $slug): void {
            $model->forceFill([
                'slug' => $slug,
            ])->saveQuietly();
        });

        return $this->syncChanged($model, $previousSlug);
    }

    public function syncChanged(Model $model, mixed $previousSlug): bool
    {
        return $this->syncSlugRedirectAction->handle(
            $model,
            $this->normalizeComparableString($previousSlug),
        );
    }

    private function currentSlug(Model $model): mixed
    {
        if (! $model->exists || $model->getKey() === null) {
            return $model->getAttribute('slug');
        }

        $resolved = $model::query()
            ->whereKey($model->getKey())
            ->value('slug');

        return $resolved ?? $model->getAttribute('slug');
    }
}
