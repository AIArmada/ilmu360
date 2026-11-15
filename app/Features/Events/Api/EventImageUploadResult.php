<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class EventImageUploadResult
{
    public function __construct(
        public string $eventId,
        public string $routeKey,
        public string $slug,
        public string $title,
        public string $publicUrl,
        public string $collection,
        public string $mediaId,
        public string $mediaUrl,
        public string $mediaCollection,
        public string $mimeType,
        public string $fileName,
        public string $name,
        public int $size,
    ) {}

    /**
     * @return array{
     *   event: array{id: string, route_key: string, slug: string, title: string, public_url: string},
     *   collection: string,
     *   media: array{id: string, url: string, collection: string, mime_type: string, file_name: string, name: string, size: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'event' => [
                'id' => $this->eventId,
                'route_key' => $this->routeKey,
                'slug' => $this->slug,
                'title' => $this->title,
                'public_url' => $this->publicUrl,
            ],
            'collection' => $this->collection,
            'media' => [
                'id' => $this->mediaId,
                'url' => $this->mediaUrl,
                'collection' => $this->mediaCollection,
                'mime_type' => $this->mimeType,
                'file_name' => $this->fileName,
                'name' => $this->name,
                'size' => $this->size,
            ],
        ];
    }
}
