<?php

declare(strict_types=1);

namespace App\Features\Institutions\Listeners;

use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Platform\Media\MediaChangeListener;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class InstitutionMediaCacheListener implements MediaChangeListener
{
    public function __construct(private InstitutionListingCacheVersion $versions) {}

    public function mediaChanged(Media $media, string $change): void
    {
        if ($media->model_type === 'institution') {
            $this->versions->bump();
        }
    }
}
