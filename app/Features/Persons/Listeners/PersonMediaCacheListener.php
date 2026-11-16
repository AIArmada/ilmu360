<?php

declare(strict_types=1);

namespace App\Features\Persons\Listeners;

use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Platform\Media\MediaChangeListener;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class PersonMediaCacheListener implements MediaChangeListener
{
    public function __construct(private PersonListingCacheVersion $versions) {}

    public function mediaChanged(Media $media, string $change): void
    {
        if ($media->model_type === 'person') {
            $this->versions->bump();
        }
    }
}
