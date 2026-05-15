<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Event;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait UploadsEventImage
{
    /**
     * @return array<string, Type>
     */
    protected function eventImageUploadInputSchema(JsonSchema $schema): array
    {
        return [
            'event_key' => $schema->string()
                ->required()
                ->min(1)
                ->description('Event UUID or slug/route key. Example: tadabbur-isu-semasa-ummah-qdkhqqn.'),
            'image' => $schema->object([
                'filename' => $schema->string()->required()->description('Filename including extension, e.g. event-cover.jpg.'),
                'content_base64' => $schema->string()->nullable()->description('Base64-encoded image content.'),
                'content_url' => $schema->string()->nullable()->description('HTTPS URL to fetch the image content.'),
                'mime_type' => $schema->string()->nullable()->description('MIME type of the image, e.g. image/jpeg, image/png, image/webp. Auto-detected if omitted.'),
            ])
                ->required()
                ->description('Image file descriptor. Pass either {content_base64, filename} or {content_url, filename}. Avoid connector file rewrite paths in proxied environments.'),
            'creative_direction' => $schema->string()
                ->nullable()
                ->description('Optional note about the creative direction saved as metadata on the media item.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function eventImageUploadOutputSchema(JsonSchema $schema): array
    {
        return [
            'event' => $schema->object([
                'id' => $schema->string()->required(),
                'route_key' => $schema->string()->required(),
                'slug' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'public_url' => $schema->string()->required(),
            ])->required(),
            'collection' => $schema->string()->required()->description("The media collection the image was saved to ('cover' or 'poster')."),
            'media' => $schema->object([
                'id' => $schema->string()->required(),
                'url' => $schema->string()->required(),
                'collection' => $schema->string()->required(),
                'mime_type' => $schema->string()->required(),
                'file_name' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'size' => $schema->integer()->required(),
            ])->required()->description('The stored Spatie MediaLibrary item.'),
        ];
    }

    /**
     * Normalize the raw `image` argument into a file descriptor array.
     *
     * Some MCP clients (e.g. ChatGPT with `openai/fileParams`) serialize object
     * parameters as a JSON-encoded string instead of a JSON object. This method
     * accepts both formats so upload tools work correctly regardless of the client.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException if the value is neither a valid descriptor array
     *                             nor a JSON-encoded descriptor object
     */
    protected function normalizeImageDescriptor(mixed $value, ?string $traceId = null): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            Log::debug('mcp.image_upload: descriptor received as associative array', [
                'trace_id' => $traceId,
                'keys' => array_keys($value),
            ]);

            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded) && ! array_is_list($decoded)) {
                Log::debug('mcp.image_upload: descriptor received as JSON-encoded string, decoded successfully', [
                    'trace_id' => $traceId,
                    'keys' => array_keys($decoded),
                    'json_length' => strlen($value),
                ]);

                return $decoded;
            }

            Log::debug('mcp.image_upload: descriptor received as string but could not be decoded as JSON object', [
                'trace_id' => $traceId,
                'type' => gettype($value),
                'json_error' => json_last_error_msg(),
                'preview' => substr($value, 0, 100),
            ]);
        } else {
            Log::debug('mcp.image_upload: descriptor received with unexpected type', [
                'trace_id' => $traceId,
                'type' => gettype($value),
            ]);
        }

        throw ValidationException::withMessages([
            'image' => ['The image must be a valid file descriptor object.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    protected function enforceEventDescriptorHasContentSource(array $descriptor): void
    {
        $base64Value = $descriptor['content_base64']
            ?? $descriptor['contentBase64']
            ?? $descriptor['base64']
            ?? $descriptor['data']
            ?? null;

        $contentUrl = $descriptor['content_url']
            ?? $descriptor['contentUrl']
            ?? $descriptor['url']
            ?? null;

        if (is_string($base64Value) && trim($base64Value) !== '') {
            return;
        }

        if (is_string($contentUrl) && trim($contentUrl) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'image' => ['Event image uploads require either content_base64 or content_url.'],
        ]);
    }

    protected function eventImageUploadResponse(Event $event, Media $media, string $collection): ResponseFactory
    {
        $url = $media->getUrl();
        $text = "Uploaded {$collection} image for ".($event->title).'.';

        if ($url !== '') {
            $text .= "\nSaved media URL: {$url}";
        }

        return Response::structured([
            'event' => [
                'id' => (string) $event->getKey(),
                'route_key' => (string) $event->getRouteKey(),
                'slug' => (string) $event->slug,
                'title' => (string) $event->title,
                'public_url' => route('events.show', ['event' => $event->slug], false),
            ],
            'collection' => $collection,
            'media' => [
                'id' => (string) $media->getKey(),
                'url' => $url,
                'collection' => (string) $media->collection_name,
                'mime_type' => (string) $media->mime_type,
                'file_name' => (string) $media->file_name,
                'name' => (string) $media->name,
                'size' => (int) $media->size,
            ],
        ]);
    }

    protected function startEventImageUploadTrace(Request $request, string $toolName): string
    {
        $traceId = (string) Str::ulid();
        $arguments = $request->all();
        $image = $arguments['image'] ?? null;

        Log::debug('mcp.image_upload.trace: request_received', [
            'trace_id' => $traceId,
            'tool' => $toolName,
            'argument_keys' => array_keys($arguments),
            'has_image' => array_key_exists('image', $arguments),
            'image_summary' => $this->imageInputSummary($image),
            'has_creative_direction' => isset($arguments['creative_direction']) && is_string($arguments['creative_direction']) && $arguments['creative_direction'] !== '',
        ]);

        return $traceId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logEventImageUploadTrace(string $traceId, string $stage, array $context = []): void
    {
        Log::debug("mcp.image_upload.trace: {$stage}", array_merge([
            'trace_id' => $traceId,
        ], $context));
    }

    /**
     * @return array<string, mixed>
     */
    private function imageInputSummary(mixed $image): array
    {
        if (is_array($image)) {
            return [
                'type' => 'array',
                'keys' => array_keys($image),
                'has_download_url' => isset($image['download_url']) || isset($image['downloadUrl']),
                'has_content_base64' => isset($image['content_base64']) || isset($image['contentBase64']) || isset($image['data']) || isset($image['base64']),
                'has_file_id' => isset($image['file_id']) || isset($image['fileId']),
            ];
        }

        if (is_string($image)) {
            return [
                'type' => 'string',
                'length' => strlen($image),
                'starts_with_json_object' => str_starts_with(trim($image), '{'),
            ];
        }

        return [
            'type' => gettype($image),
        ];
    }
}
