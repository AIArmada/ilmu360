<?php

declare(strict_types=1);

namespace App\Platform\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Tagged hook for media lifecycle side effects outside the media library's
 * own storage bookkeeping (directory cache versions, future Directory work).
 */
interface MediaChangeListener
{
    public const string TAG = 'platform.media.change_listeners';

    /**
     * @param  'created'|'updated'|'deleted'  $change
     */
    public function mediaChanged(Media $media, string $change): void;
}
