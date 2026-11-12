<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class EloquentMediaCollectionSync implements MediaCollectionSync
{
    public function deleteMedia(Media $media): void
    {
        $media->delete();
    }

    public function clearCollection(HasMedia $model, string $collection): void
    {
        $model->clearMediaCollection($collection);
    }

    public function syncSingle(HasMedia $model, ?UploadedFile $file, string $collection): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $model->clearMediaCollection($collection);
        $this->addMedia($model, $file, $collection);
    }

    /**
     * @param  array<int, UploadedFile>|null  $files
     */
    public function syncMultiple(HasMedia $model, ?array $files, string $collection, bool $replace = false): void
    {
        if ($replace) {
            $model->clearMediaCollection($collection);
        }

        foreach ($files ?? [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $this->addMedia($model, $file, $collection);
        }
    }

    /**
     * @param  array<string, mixed>  $customProperties
     */
    public function syncFromDisk(
        HasMedia $model,
        string $path,
        string $disk,
        string $collection,
        ?string $fileName = null,
        ?string $name = null,
        array $customProperties = [],
    ): void {
        $modelRecord = $model instanceof Model ? $model : null;
        $fileName ??= basename($path);
        $originalFileName = is_string($customProperties['original_file_name'] ?? null)
            ? $customProperties['original_file_name']
            : $fileName;
        $name ??= MediaFileNamer::resolveDisplayNameFromModel($modelRecord, $collection, $originalFileName);

        $model
            ->addMediaFromDisk($path, $disk)
            ->preservingOriginal()
            ->usingFileName($fileName)
            ->usingName($name)
            ->withCustomProperties([
                'collection' => $collection,
                'original_file_name' => $originalFileName,
                ...$customProperties,
            ])
            ->toMediaCollection($collection);
    }

    /**
     * @param  iterable<int, Media>  $stagedMedia
     * @param  (Closure(Media): bool)|null  $specialHandler
     */
    public function applyStagedMedia(HasMedia $model, iterable $stagedMedia, ?Closure $specialHandler = null): void
    {
        foreach ($stagedMedia as $media) {
            if ($specialHandler instanceof Closure && $specialHandler($media) === true) {
                continue;
            }

            $field = (string) $media->getCustomProperty('contribution_field', 'cover');
            $isSingle = (bool) $media->getCustomProperty('is_single', true);

            if ($isSingle) {
                $model->clearMediaCollection($field);
            }

            $model->addMediaFromDisk($media->getPathRelativeToRoot(), $media->disk)
                ->usingFileName($media->file_name)
                ->toMediaCollection($field);

            $this->deleteMedia($media);
        }
    }

    private function addMedia(HasMedia $model, UploadedFile $file, string $collection): void
    {
        $modelRecord = $model instanceof Model ? $model : null;
        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension()));
        $suffix = strtolower(substr((string) Str::ulid(), -8));
        $fileName = MediaFileNamer::resolveBaseNameFromModel($modelRecord)."-{$suffix}";

        if ($extension !== '') {
            $fileName .= ".{$extension}";
        }

        $model
            ->addMedia($file)
            ->usingFileName($fileName)
            ->usingName(MediaFileNamer::resolveDisplayNameFromModel(
                $modelRecord,
                $collection,
                $file->getClientOriginalName(),
            ))
            ->withCustomProperties([
                'collection' => $collection,
                'original_file_name' => $file->getClientOriginalName(),
            ])
            ->toMediaCollection($collection);
    }
}
