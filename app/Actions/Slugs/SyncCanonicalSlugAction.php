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
        if ($this->normalizeComparableString($model->getAttribute('slug')) === $this->normalizeComparableString($slug)) {
            return false;
        }

        $previousSlug = $this->normalizeComparableString($model->getAttribute('slug'));
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
}
