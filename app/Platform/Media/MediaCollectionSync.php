<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Stable media collection write boundary for feature code.
 *
 * Keeps upload clearing, single-file replacement and multi-file appends in
 * one place so feature actions never depend on the underlying media storage
 * implementation.
 */
interface MediaCollectionSync
{
    public function clearCollection(HasMedia $model, string $collection): void;

    public function syncSingle(HasMedia $model, ?UploadedFile $file, string $collection): void;

    /**
     * @param  array<int, UploadedFile>|null  $files
     */
    public function syncMultiple(HasMedia $model, ?array $files, string $collection, bool $replace = false): void;
}
