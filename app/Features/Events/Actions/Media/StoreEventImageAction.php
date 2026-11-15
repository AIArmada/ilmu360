<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Media;

use App\Features\Events\Api\UploadEventImageInput;
use App\Models\Event;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\StagedUploadFactory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StoreEventImageAction
{
    public function __construct(
        private MediaCollectionSync $mediaCollections,
        private StagedUploadFactory $stagedUploads,
    ) {}

    /**
     * @return array{event: Event, media: Media}
     */
    public function handle(UploadEventImageInput $input): array
    {
        $event = Event::query()->findOrFail($input->eventId);
        try {
            $file = $this->stagedUploads->toUploadedFile($input->file);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'image' => ['The event image upload is no longer available.'],
            ]);
        }

        $mimeType = $file->getMimeType();

        if (! $file->isValid() || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'image' => ['Event images must be valid JPEG, PNG, or WebP files.'],
            ]);
        }

        $size = $file->getSize();

        if ($size === false || $size > 20 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image' => ['Event images may not exceed 20 MB.'],
            ]);
        }

        $customProperties = [
            'source' => 'mcp_uploaded',
        ];

        if (is_string($input->sourceFileId) && trim($input->sourceFileId) !== '') {
            $customProperties['chatgpt_file_id'] = trim($input->sourceFileId);
        }

        if (is_string($input->creativeDirection) && $input->creativeDirection !== '') {
            $customProperties['creative_direction'] = $input->creativeDirection;
        }

        $title = is_string($event->title) && $event->title !== ''
            ? $event->title
            : (string) $event->getKey();

        $media = $this->mediaCollections->storeSingle(
            model: $event,
            file: $file,
            collection: $input->collection->value,
            name: $input->collection->mediaLabel().' - '.$title,
            customProperties: $customProperties,
        );

        return [
            'event' => $event,
            'media' => $media,
        ];
    }
}
