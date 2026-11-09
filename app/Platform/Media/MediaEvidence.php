<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * Canonical policy for evidence collections shared by feedback and reports.
 *
 * Disks stay model-owned: feedback evidence is private while report evidence
 * uses the public media disk.
 */
final class MediaEvidence
{
    public const string COLLECTION = 'evidence';

    public const string THUMB_CONVERSION = 'thumb';

    public const string PRIVATE_DISK = 'app_feedback_private';

    public const int MAX_FILES = 8;

    /**
     * @return list<string>
     */
    public static function mimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];
    }

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    }

    public static function mimeRule(): string
    {
        return 'mimes:'.implode(',', self::extensions());
    }

    public static function extensionRule(): string
    {
        return 'extensions:'.implode(',', self::extensions());
    }

    public static function maxFileSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    public static function publicDisk(): string
    {
        return (string) config('media-library.disk_name');
    }

    public static function configureUpload(
        SpatieMediaLibraryFileUpload $upload,
        ?string $disk = null,
        ?string $visibility = null,
        bool $withConversion = true,
    ): SpatieMediaLibraryFileUpload {
        $upload
            ->collection(self::COLLECTION)
            ->multiple()
            ->reorderable()
            ->maxFiles(self::MAX_FILES)
            ->maxSize(self::maxFileSizeKb())
            ->acceptedFileTypes(self::mimeTypes());

        if ($disk !== null) {
            $upload->disk($disk);
        }

        if ($visibility !== null) {
            $upload->visibility($visibility);
        }

        if ($withConversion) {
            $upload->conversion(self::THUMB_CONVERSION);
        }

        return $upload;
    }
}
