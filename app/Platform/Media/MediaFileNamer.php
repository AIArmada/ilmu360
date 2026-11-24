<?php

namespace App\Platform\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileNamer\FileNamer;

/**
 * Custom file namer for Spatie Media Library.
 *
 * Handles naming of conversion and responsive image files.
 * Original file naming is handled globally via SpatieMediaLibraryFileUpload::configureUsing()
 * in AppServiceProvider, which calls resolveBaseNameFromModel().
 *
 * Examples:
 *   Original:   forum-makan-01jxab3k.webp
 *   Conversion: forum-makan-01jxab3k-thumb.webp
 *   Responsive: forum-makan-01jxab3k___thumb_400_300.webp
 *
 * For models without a slug (e.g. Report), falls back to the collection name:
 *   evidence-01jxab3k.jpg
 */
class MediaFileNamer extends FileNamer
{
    #[\Override]
    public function originalFileName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    public function conversionFileName(string $fileName, Conversion $conversion): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        return "{$baseName}-{$conversion->getName()}";
    }

    public function responsiveFileName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    /**
     * Generate the canonical immutable storage filename: {base}-{8-char-ulid}.{ext}.
     */
    public static function generateStorageFileName(?Model $model, string $extension, ?string $baseName = null): string
    {
        $base = filled($baseName)
            ? (self::slugifyBaseName($baseName) ?? 'media')
            : self::resolveBaseNameFromModel($model);

        $suffix = strtolower(substr((string) Str::ulid(), -8));
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));

        return $normalizedExtension !== ''
            ? "{$base}-{$suffix}.{$normalizedExtension}"
            : "{$base}-{$suffix}";
    }

    /**
     * Resolve a human-readable, slug-safe base name from a model.
     *
     * Used by AppServiceProvider, EloquentMediaCollectionSync, and media workflows
     * to generate slug-based filenames for all media uploads.
     */
    public static function resolveBaseNameFromModel(?Model $model): string
    {
        if (! $model instanceof Model) {
            return 'media';
        }

        // Priority: slug -> name -> title -> label
        foreach (['slug', 'name', 'title', 'label'] as $attribute) {
            if (! isset($model->{$attribute})) {
                continue;
            }

            $slugged = self::slugifyBaseName($model->getAttribute($attribute));

            if ($slugged !== null) {
                return $slugged;
            }
        }

        // Fallback: use slugified morph alias or class basename
        $morphMap = array_flip(Relation::morphMap());
        $fallback = $morphMap[$model::class] ?? class_basename($model);

        return self::slugifyBaseName($fallback) ?? 'media';
    }

    protected static function slugifyBaseName(mixed $value): ?string
    {
        if (! filled($value) || ! is_scalar($value)) {
            return null;
        }

        $slugged = rtrim(str((string) $value)->slug()->limit(80, '')->toString(), '-');

        return $slugged !== '' ? $slugged : null;
    }

    /**
     * Resolve a readable media name for the Media model's "name" column.
     */
    public static function resolveDisplayNameFromModel(
        ?Model $model,
        string $collection,
        string $originalFileName
    ): string {
        $collectionLabel = self::resolveCollectionLabel($collection);
        $subject = self::resolveSubjectLabel($model);

        if (filled($subject)) {
            return "{$collectionLabel} - {$subject}";
        }

        $originalLabel = Str::of(pathinfo($originalFileName, PATHINFO_FILENAME))
            ->replace(['_', '-'], ' ')
            ->squish()
            ->title()
            ->toString();

        if (filled($originalLabel)) {
            return "{$collectionLabel} - {$originalLabel}";
        }

        return $collectionLabel;
    }

    protected static function resolveCollectionLabel(string $collection): string
    {
        return match ($collection) {
            'poster' => 'Event Poster',
            'cover' => 'Cover Image',
            'logo' => 'Logo',
            'avatar' => 'Avatar',
            'main' => 'Main Image',
            'square' => 'Square Photo',
            'front_cover' => 'Front Cover',
            'back_cover' => 'Back Cover',
            'gallery' => 'Gallery Image',
            'qr' => 'QR Code',
            'evidence' => 'Evidence File',
            default => Str::of($collection)
                ->replace(['_', '-'], ' ')
                ->headline()
                ->toString(),
        };
    }

    protected static function resolveSubjectLabel(?Model $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        foreach (['title', 'name', 'label', 'slug'] as $attribute) {
            if (isset($model->{$attribute})) {
                $value = $model->getAttribute($attribute);

                if (filled($value) && is_scalar($value)) {
                    return Str::of((string) $value)
                        ->replace(['_', '-'], ' ')
                        ->squish()
                        ->title()
                        ->toString();
                }
            }
        }

        return null;
    }
}
