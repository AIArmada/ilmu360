<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Actions\Media\StoreEventImageAction;
use App\Features\Events\Api\EventImageUploadResult;
use App\Features\Events\Api\EventImageUploads;
use App\Features\Events\Api\UploadEventImageInput;

final readonly class EventImageUploadsService implements EventImageUploads
{
    public function __construct(private StoreEventImageAction $storeImage) {}

    public function upload(UploadEventImageInput $input): EventImageUploadResult
    {
        $stored = $this->storeImage->handle($input);
        $event = $stored['event'];
        $media = $stored['media'];

        return new EventImageUploadResult(
            eventId: (string) $event->getKey(),
            routeKey: (string) $event->getRouteKey(),
            slug: (string) $event->slug,
            title: (string) $event->title,
            publicUrl: (string) route('events.show', ['event' => $event->slug], false),
            collection: $input->collection->value,
            mediaId: (string) $media->getKey(),
            mediaUrl: (string) $media->getUrl(),
            mediaCollection: (string) $media->collection_name,
            mimeType: (string) $media->mime_type,
            fileName: (string) $media->file_name,
            name: (string) $media->name,
            size: (int) $media->size,
        );
    }
}
