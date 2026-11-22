<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Features\Events\Api\EventMedia;
use App\Features\Events\Models\Event;
use App\Platform\Media\MediaCollectionSync;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Default implementation of the Events staged-media boundary.
 *
 * The Platform media sync owns the generic single/multi collection chain;
 * only donation-QR rows are routed to DonationChannels.
 */
final readonly class EventMediaService implements EventMedia
{
    public function __construct(
        private MediaCollectionSync $mediaCollectionSync,
        private DonationQrWorkflow $donationQrMedia,
    ) {}

    public function applyStagedMedia(string $eventId, string $requestMorphType, string $requestId, array $stagedMediaIds): void
    {
        $event = Event::query()->findOrFail($eventId);

        $stagedMediaIds = array_values(array_unique($stagedMediaIds));

        if ($stagedMediaIds === []) {
            return;
        }

        $staged = Media::query()
            ->where('model_type', $requestMorphType)
            ->where('model_id', $requestId)
            ->where('collection_name', 'pending_media')
            ->whereKey($stagedMediaIds)
            ->get()
            ->keyBy(fn (Media $media): string => (string) $media->getKey());

        if ($staged->count() !== count($stagedMediaIds)) {
            throw (new ModelNotFoundException)->setModel(Media::class, $stagedMediaIds);
        }

        $ordered = array_map(fn (string $mediaId): Media => $staged->get($mediaId), $stagedMediaIds);

        $this->mediaCollectionSync->applyStagedMedia(
            $event,
            $ordered,
            fn (Media $media): bool => $this->applySpecialStagedMedia($event, $media),
        );
    }

    private function applySpecialStagedMedia(HasMedia $event, Media $media): bool
    {
        if ((string) $media->getCustomProperty('contribution_field', 'cover') !== 'donation_qr') {
            return false;
        }

        $this->donationQrMedia->attachStagedDonationQr($event->getMorphClass(), (string) $event->getKey(), (string) $media->getKey());

        return true;
    }
}
