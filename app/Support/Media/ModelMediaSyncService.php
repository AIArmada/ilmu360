<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

class ModelMediaSyncService
{
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

    protected function addMedia(HasMedia $model, UploadedFile $file, string $collection): void
    {
        $modelRecord = $model instanceof Model ? $model : null;
        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension()));
        $suffix = strtolower(substr((string) Str::ulid(), 0, 8));
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
