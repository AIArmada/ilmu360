<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Features\Events\Api\EventImageCollection;
use App\Features\Events\Api\EventImageUploadResult;
use App\Features\Events\Api\EventImageUploads;
use App\Features\Events\Api\UploadEventImageInput;
use App\Models\Event;
use App\Platform\Mcp\McpFilePayloadNormalizer;
use App\Platform\Media\StagedUploadFactory;
use App\Support\Api\Admin\AdminResourceRegistry;
use App\Support\Api\Member\MemberResourceRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait UploadsEventImage
{
    use DefinesMcpFileDescriptorSchema;

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
            'image' => $this->fileDescriptorSchema($schema, supportsFileId: true)
                ->required()
                ->description('Image file descriptor. Provide exactly one of content_base64, content_url, or download_url with filename.'),
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
            ])->required()->description('The stored media item.'),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
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

        throw ValidationException::withMessages([
            'image' => ['The image must be a file descriptor object.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    protected function enforceEventDescriptorHasContentSource(array $descriptor): void
    {
        $sources = array_filter(
            ['content_base64', 'content_url', 'download_url'],
            static fn (string $key): bool => is_string($descriptor[$key] ?? null)
                && trim((string) $descriptor[$key]) !== '',
        );

        if (count($sources) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'image' => ['Event image uploads require exactly one of content_base64, content_url, or download_url.'],
        ]);
    }

    protected function resolveEventId(
        AdminResourceRegistry|MemberResourceRegistry $registry,
        string $eventKey,
    ): ?string {
        $resourceClass = $registry->resolve('events');

        abort_unless(is_string($resourceClass) && $registry->canAccessResource($resourceClass), 404);

        $query = $registry->queryFor($resourceClass);
        $model = $query->getModel();

        $record = Str::isUuid($eventKey)
            ? $query->where($model->qualifyColumn($model->getRouteKeyName()), $eventKey)->first()
            : $query->where($model->qualifyColumn('slug'), $eventKey)->first();

        return $record instanceof Event ? (string) $record->getKey() : null;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    protected function uploadEventImage(
        EventImageUploads $uploads,
        string $eventId,
        EventImageCollection $collection,
        array $descriptor,
        ?string $creativeDirection,
        string $traceId,
    ): EventImageUploadResult {
        $normalizer = app(McpFilePayloadNormalizer::class);
        $field = $collection->value;
        $normalized = $normalizer->normalize(
            [$field => $this->descriptorForNormalization($descriptor)],
            [$field => [
                'type' => 'file',
                'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_file_size_kb' => 20480,
            ]],
        );

        Log::debug('mcp.image_upload: descriptor normalized', [
            'trace_id' => $traceId,
            'event_id' => $eventId,
            'collection' => $field,
            'temporary_paths_count' => count($normalized['temporary_paths']),
        ]);

        try {
            $file = $normalized['payload'][$field] ?? null;

            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'image' => ['The event image could not be normalized into an upload.'],
                ]);
            }

            $sourceFileId = $descriptor['file_id'] ?? null;

            return $uploads->upload(new UploadEventImageInput(
                eventId: $eventId,
                collection: $collection,
                file: app(StagedUploadFactory::class)->fromUploadedFile($file),
                creativeDirection: $creativeDirection,
                sourceFileId: is_string($sourceFileId) ? $sourceFileId : null,
            ));
        } finally {
            $normalizer->cleanup($normalized['temporary_paths']);

            Log::debug('mcp.image_upload: temporary files cleanup completed', [
                'trace_id' => $traceId,
                'event_id' => $eventId,
                'collection' => $field,
                'temporary_paths_count' => count($normalized['temporary_paths']),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function descriptorForNormalization(array $descriptor): array
    {
        $sourceFileId = $descriptor['file_id'] ?? null;

        if ($sourceFileId !== null && ! is_string($sourceFileId)) {
            throw ValidationException::withMessages([
                'image.file_id' => ['The ChatGPT file identifier must be a string.'],
            ]);
        }

        unset($descriptor['file_id']);

        return $descriptor;
    }

    protected function eventImageUploadResponse(EventImageUploadResult $result): ResponseFactory
    {
        return Response::structured($result->toArray());
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
                'has_content_base64' => isset($image['content_base64']),
                'has_content_url' => isset($image['content_url']),
                'has_download_url' => isset($image['download_url']),
                'has_file_id' => isset($image['file_id']),
            ];
        }

        if (is_string($image)) {
            return [
                'type' => 'string',
                'length' => strlen($image),
            ];
        }

        return [
            'type' => gettype($image),
        ];
    }
}
