<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Platform\Media\MediaCollectionSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Sync reference imagery through the Platform media boundary.
 */
final readonly class SyncReferenceMediaAction
{
    public function __construct(
        private MediaCollectionSync $mediaSync,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Model $reference, array $data): void
    {
        if (! $reference instanceof HasMedia) {
            return;
        }

        if (($data['clear_front_cover'] ?? false) === true) {
            $this->mediaSync->clearCollection($reference, 'front_cover');
        }

        if (($data['clear_back_cover'] ?? false) === true) {
            $this->mediaSync->clearCollection($reference, 'back_cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSync->clearCollection($reference, 'gallery');
        }

        $frontCover = $data['front_cover'] ?? null;
        $backCover = $data['back_cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSync->syncSingle(
            $reference,
            $frontCover instanceof UploadedFile ? $frontCover : null,
            'front_cover',
        );
        $this->mediaSync->syncSingle(
            $reference,
            $backCover instanceof UploadedFile ? $backCover : null,
            'back_cover',
        );
        $this->mediaSync->syncMultiple(
            $reference,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }
}
