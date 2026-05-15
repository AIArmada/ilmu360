<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class EventImageUploadService
{
    public const array ACCEPTED_COLLECTIONS = ['cover', 'poster'];

    /**
     * Upload a single image from an MCP file descriptor to an event's cover or poster collection.
     *
     * The descriptor must contain either:
     *   - {download_url, filename} — fetches the image from the URL (ChatGPT download_url pattern)
     *   - {content_base64, filename} — decodes the base64-encoded image
     *
     * Optional descriptor keys: file_id (ChatGPT reference metadata), mime_type.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function upload(
        Event $event,
        string $collection,
        array $descriptor,
        ?string $creativeDirection = null,
        ?string $traceId = null,
    ): Media {
        abort_unless(in_array($collection, self::ACCEPTED_COLLECTIONS, true), 422, 'Collection must be one of: '.implode(', ', self::ACCEPTED_COLLECTIONS));

        Log::debug('mcp.image_upload: upload started', [
            'trace_id' => $traceId,
            'event_id' => $event->getKey(),
            'event_slug' => $event->slug,
            'collection' => $collection,
            'descriptor_keys' => array_keys($descriptor),
            'has_creative_direction' => $creativeDirection !== null,
        ]);

        $normalizer = app(McpFilePayloadNormalizer::class);

        $contract = [
            'type' => 'file',
            'accepted_mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size_kb' => 20480,
        ];

        $result = $normalizer->normalize(
            [$collection => $descriptor],
            [$collection => $contract],
        );

        Log::debug('mcp.image_upload: descriptor normalized by mcp file normalizer', [
            'trace_id' => $traceId,
            'event_id' => $event->getKey(),
            'collection' => $collection,
            'temporary_paths_count' => count($result['temporary_paths']),
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $result['payload'][$collection];

        Log::debug('mcp.image_upload: file normalized, staging for media library', [
            'trace_id' => $traceId,
            'event_id' => $event->getKey(),
            'collection' => $collection,
            'original_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => $uploadedFile->getMimeType(),
            'size_bytes' => $uploadedFile->getSize(),
        ]);

        try {
            $media = $this->storeMedia($event, $collection, $uploadedFile, $descriptor, $creativeDirection, $traceId);

            Log::debug('mcp.image_upload: media stored successfully', [
                'trace_id' => $traceId,
                'event_id' => $event->getKey(),
                'collection' => $collection,
                'media_id' => $media->getKey(),
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size_bytes' => $media->size,
            ]);

            return $media;
        } finally {
            $normalizer->cleanup($result['temporary_paths']);

            Log::debug('mcp.image_upload: temporary files cleanup completed', [
                'trace_id' => $traceId,
                'event_id' => $event->getKey(),
                'collection' => $collection,
                'temporary_paths_count' => count($result['temporary_paths']),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function storeMedia(
        Event $event,
        string $collection,
        UploadedFile $uploadedFile,
        array $descriptor,
        ?string $creativeDirection,
        ?string $traceId,
    ): Media {
        $label = $collection === 'poster' ? 'Event Poster Image' : 'Event Cover Image';
        $fileId = $this->stringFromDescriptor($descriptor, ['file_id', 'fileId']);

        $customProperties = [
            'collection' => $collection,
            'original_file_name' => $uploadedFile->getClientOriginalName(),
            'source' => 'mcp_uploaded',
        ];

        if ($fileId !== null) {
            $customProperties['chatgpt_file_id'] = $fileId;
        }

        if ($creativeDirection !== null && $creativeDirection !== '') {
            $customProperties['creative_direction'] = $creativeDirection;
        }

        Log::debug('mcp.image_upload: preparing media library write', [
            'trace_id' => $traceId,
            'event_id' => $event->getKey(),
            'collection' => $collection,
            'uploaded_file_original_name' => $uploadedFile->getClientOriginalName(),
            'has_chatgpt_file_id' => isset($customProperties['chatgpt_file_id']),
            'has_creative_direction' => isset($customProperties['creative_direction']),
        ]);

        $title = is_string($event->title) && $event->title !== '' ? $event->title : (string) $event->getKey();

        return $event
            ->addMedia($uploadedFile)
            ->usingName("{$label} - {$title}")
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  list<string>  $keys
     */
    private function stringFromDescriptor(array $descriptor, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $descriptor[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
