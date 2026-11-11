<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Platform\Media\MediaCollectionSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * Synchronize venue media collections from admin payloads.
 */
final readonly class SyncVenueMediaAction
{
    public function __construct(
        private MediaCollectionSync $mediaSync,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Model $venue, array $data): void
    {
        if (! $venue instanceof HasMedia) {
            throw new RuntimeException('Venues must support media.');
        }

        if (($data['clear_main'] ?? false) === true) {
            $this->mediaSync->clearCollection($venue, 'main');
        }

        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSync->clearCollection($venue, 'cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSync->clearCollection($venue, 'gallery');
        }

        $main = $data['main'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSync->syncSingle(
            $venue,
            $main instanceof UploadedFile ? $main : null,
            'main',
        );
        $this->mediaSync->syncSingle(
            $venue,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSync->syncMultiple(
            $venue,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }
}
