<?php

declare(strict_types=1);

namespace App\Platform\Media;

use App\Support\Media\ModelMediaSyncService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Delegates collection writes to the existing media library adapter so
 * naming, custom properties and Spatie conversion behaviour stay unchanged.
 */
final class EloquentMediaCollectionSync implements MediaCollectionSync
{
    public function __construct(
        private readonly ModelMediaSyncService $mediaSyncService,
    ) {}

    public function clearCollection(HasMedia $model, string $collection): void
    {
        $this->mediaSyncService->clearCollection($model, $collection);
    }

    public function syncSingle(HasMedia $model, ?UploadedFile $file, string $collection): void
    {
        $this->mediaSyncService->syncSingle($model, $file, $collection);
    }

    public function syncMultiple(HasMedia $model, ?array $files, string $collection, bool $replace = false): void
    {
        $this->mediaSyncService->syncMultiple($model, $files, $collection, $replace);
    }
}
