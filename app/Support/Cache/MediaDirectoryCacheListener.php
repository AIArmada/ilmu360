<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Platform\Media\MediaChangeListener;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Bridges media changes to directory cache invalidation until the Directory
 * capability owns its media listener in D1.
 */
final readonly class MediaDirectoryCacheListener implements MediaChangeListener
{
    public function __construct(private PublicDirectoryCacheVersion $cacheVersion) {}

    public function mediaChanged(Media $media, string $change): void
    {
        $this->cacheVersion->bumpForMedia($media);
    }
}
