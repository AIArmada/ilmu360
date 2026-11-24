<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final readonly class EloquentMediaCollectionSync implements MediaCollectionSync
{
    public function __construct(private Filesystem $filesystem) {}

    public function cleanUpFailedWrites(HasMedia $model, Closure $write): void
    {
        $mediaBefore = $model->media()->pluck('id')->all();

        try {
            $write();
        } catch (Throwable $exception) {
            try {
                foreach ($model->media()->whereNotIn('id', $mediaBefore)->get() as $media) {
                    if ($media instanceof Media) {
                        $this->removeFiles($media);
                    }
                }
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        foreach ($model->media()->whereNotIn('id', $mediaBefore)->get() as $media) {
            if ($media instanceof Media) {
                $this->removeFilesOnRollback($media);
            }
        }
    }

    public function removeFilesOnRollback(Media $media): void
    {
        $connection = $media->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterRollBack(function () use ($media): void {
                $this->removeFiles($media);
            });
        }
    }

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

        $this->storeSingle($model, $file, $collection);
    }

    /**
     * @param  array<string, mixed>  $customProperties
     */
    public function storeSingle(
        HasMedia $model,
        UploadedFile $file,
        string $collection,
        ?string $name = null,
        array $customProperties = [],
    ): Media {
        $media = $this->addMedia($model, $file, $collection, $name, $customProperties);
        $model->clearMediaCollectionExcept($collection, [$media]);

        return $media;
    }

    /**
     * @param  array<int, UploadedFile>|null  $files
     */
    public function syncMultiple(HasMedia $model, ?array $files, string $collection, bool $replace = false): void
    {
        $added = [];

        try {
            foreach ($files ?? [] as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $added[] = $this->addMedia($model, $file, $collection);
            }
        } catch (Throwable $exception) {
            foreach ($added as $media) {
                $this->deleteMedia($media);
            }

            throw $exception;
        }

        if (! $replace) {
            return;
        }

        if ($added === []) {
            $model->clearMediaCollection($collection);

            return;
        }

        $model->clearMediaCollectionExcept($collection, $added);
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
        $originalFileName = is_string($customProperties['original_file_name'] ?? null)
            ? $customProperties['original_file_name']
            : ($fileName ?? basename($path));
        $fileName ??= MediaFileNamer::generateStorageFileName($modelRecord, pathinfo($path, PATHINFO_EXTENSION));
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
        $copies = [];

        foreach ($stagedMedia as $media) {
            if ($specialHandler instanceof Closure && $specialHandler($media) === true) {
                continue;
            }

            $field = (string) $media->getCustomProperty('contribution_field', 'cover');

            if (! $model->getMediaCollection($field) instanceof \Spatie\MediaLibrary\MediaCollections\MediaCollection) {
                $this->deleteMedia($media);

                continue;
            }

            $isSingle = (bool) $media->getCustomProperty('is_single', true);

            $replacement = $model->addMediaFromDisk($media->getPathRelativeToRoot(), $media->disk)
                ->preservingOriginal()
                ->usingFileName($media->file_name)
                ->toMediaCollection($field);

            $copies[] = [$media, $field, $isSingle, $replacement];
        }

        $cleanup = function () use ($model, $copies): void {
            foreach ($copies as [$media, $field, $isSingle, $replacement]) {
                if ($isSingle) {
                    $model->getMedia($field)
                        ->filter(fn (Media $existing): bool => $existing->getKey() !== $replacement->getKey())
                        ->each->delete();
                }

                $media->delete();
            }
        };

        $cleanup();
    }

    /** @param array<string, mixed> $customProperties */
    private function addMedia(
        HasMedia $model,
        UploadedFile $file,
        string $collection,
        ?string $name = null,
        array $customProperties = [],
    ): Media {
        $modelRecord = $model instanceof Model ? $model : null;
        $extension = (string) ($file->extension() ?: $file->getClientOriginalExtension());
        $fileName = MediaFileNamer::generateStorageFileName($modelRecord, $extension);

        return $model
            ->addMedia($file)
            ->usingFileName($fileName)
            ->usingName($name ?? MediaFileNamer::resolveDisplayNameFromModel(
                $modelRecord,
                $collection,
                $file->getClientOriginalName(),
            ))
            ->withCustomProperties([
                'collection' => $collection,
                'original_file_name' => $file->getClientOriginalName(),
                ...$customProperties,
            ])
            ->toMediaCollection($collection);
    }

    private function removeFiles(Media $media): void
    {
        try {
            $this->filesystem->removeAllFiles($media);
            $this->filesystem->removeResponsiveImages($media);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
