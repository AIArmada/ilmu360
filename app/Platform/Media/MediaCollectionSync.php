<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Stable media collection write boundary for feature code.
 *
 * Keeps upload clearing, single-file replacement and multi-file appends in
 * one place so feature actions never depend on the underlying media storage
 * implementation.
 */
interface MediaCollectionSync
{
    /**
     * Remove one media row, e.g. a staged copy after it was applied.
     */
    public function deleteMedia(Media $media): void;

    public function clearCollection(HasMedia $model, string $collection): void;

    public function syncSingle(HasMedia $model, ?UploadedFile $file, string $collection): void;

    /**
     * @param  array<int, UploadedFile>|null  $files
     */
    public function syncMultiple(HasMedia $model, ?array $files, string $collection, bool $replace = false): void;

    /**
     * Copy a file already stored on a disk into a target collection.
     *
     * Used when applying media that was staged on a holding record: the file
     * keeps its stored name, while the collection and original-file custom
     * properties follow the same semantics as direct uploads.
     *
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
    ): void;
}
