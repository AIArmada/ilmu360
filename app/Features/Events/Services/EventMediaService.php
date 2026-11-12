<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Features\Events\Api\EventMedia;
use App\Platform\Media\MediaCollectionSync;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Default implementation of the Events staged-media boundary.
 *
 * The Platform media sync owns the generic single/multi collection chain;
 * only donation-QR rows are routed to their Directory owner.
 */
final readonly class EventMediaService implements EventMedia
{
    public function __construct(
        private MediaCollectionSync $mediaCollectionSync,
        private DirectoryContributionMedia $directoryContributionMedia,
    ) {}

    public function applyStagedMedia(HasMedia $event, iterable $stagedMedia): void
    {
        $this->mediaCollectionSync->applyStagedMedia(
            $event,
            $stagedMedia,
            fn (Media $media): bool => $this->applySpecialStagedMedia($event, $media),
        );
    }

    private function applySpecialStagedMedia(HasMedia $event, Media $media): bool
    {
        if ((string) $media->getCustomProperty('contribution_field', 'cover') !== 'donation_qr') {
            return false;
        }

        $this->directoryContributionMedia->attachStagedDonationQr($event, $media);

        return true;
    }
}
