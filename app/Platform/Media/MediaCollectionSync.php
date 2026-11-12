<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Closure;
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

    /**
     * Apply media staged on a holding record to its final collections.
     *
     * Each staged file keeps its stored filename and is written to the
     * collection named by its `contribution_field` custom property (`cover`
     * by default). Single-file collections are cleared first unless
     * `is_single` is false; every staged row is deleted after it is applied.
     *
     * A special handler may claim a staged file before the default chain
     * runs. Return true to skip the default chain; the handler then owns the
     * staged row (for example, the Directory QR path deletes it). Return
     * false to apply the file normally.
     *
     * @param  iterable<int, Media>  $stagedMedia
     * @param  (Closure(Media): bool)|null  $specialHandler
     */
    public function applyStagedMedia(HasMedia $model, iterable $stagedMedia, ?Closure $specialHandler = null): void;
}
