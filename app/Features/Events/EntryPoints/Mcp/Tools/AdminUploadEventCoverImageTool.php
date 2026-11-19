<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Mcp\Tools;

use App\Features\Events\Api\EventImageCollection;
use App\Features\Events\Api\EventImageUploads;
use App\Features\Events\EntryPoints\Mcp\Concerns\UploadsEventImage;
use App\Platform\Api\Admin\AdminResourceRegistry;
use App\Platform\Mcp\Tools\Admin\AbstractAdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(true)]
#[IsOpenWorld(true)]
class AdminUploadEventCoverImageTool extends AbstractAdminTool
{
    use UploadsEventImage;

    #[\Override]
    protected string $name = 'admin-upload-event-cover-image';

    #[\Override]
    protected string $title = 'Upload Event Cover Image';

    #[\Override]
    protected string $description = 'Upload and save a 16:9 website/mobile-app cover image for an admin-accessible event. Accepts image descriptors via {content_base64} or {content_url}. Use the admin-event-cover-image-prompt to get the recommended prompt and reference images before generating.';

    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly EventImageUploads $imageUploads,
    ) {
        $this->setMeta([
            'openai/toolInvocation/invoking' => 'Uploading event cover image...',
            'openai/toolInvocation/invoked' => 'Event cover image uploaded.',
            'openai/note' => 'Pass filename and exactly one of content_base64, content_url, or download_url. Required aspect ratio: 16:9.',
        ]);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $traceId = $this->startEventImageUploadTrace($request, $this->name);

        return $this->safeResponse(function () use ($request, $traceId): ResponseFactory {
            $actor = $this->authorizeAdmin($request);

            $this->logEventImageUploadTrace($traceId, 'authorized', [
                'actor_id' => $actor->getKey(),
                'actor_type' => 'admin',
            ]);

            $validated = $this->validateArguments($request, [
                'event_key' => ['required', 'string', 'min:1'],
                'image' => ['required'],
                'creative_direction' => ['nullable', 'string', 'max:2000'],
            ]);

            $this->logEventImageUploadTrace($traceId, 'arguments_validated', [
                'event_key' => (string) $validated['event_key'],
                'has_creative_direction' => is_string($validated['creative_direction'] ?? null) && $validated['creative_direction'] !== '',
            ]);

            $eventId = $this->resolveEventId($this->registry, (string) $validated['event_key']);

            $this->logEventImageUploadTrace($traceId, 'event_resolved', [
                'event_found' => is_string($eventId),
                'event_id' => $eventId,
            ]);

            abort_unless(is_string($eventId), 404);

            $imageDescriptor = $this->normalizeImageDescriptor($validated['image'], $traceId);
            $this->enforceEventDescriptorHasContentSource($imageDescriptor);

            $this->logEventImageUploadTrace($traceId, 'descriptor_normalized', [
                'descriptor_keys' => array_keys($imageDescriptor),
                'has_download_url' => isset($imageDescriptor['download_url']),
                'has_content_base64' => isset($imageDescriptor['content_base64']),
                'has_file_id' => isset($imageDescriptor['file_id']),
            ]);

            $result = $this->uploadEventImage(
                uploads: $this->imageUploads,
                eventId: $eventId,
                collection: EventImageCollection::Cover,
                descriptor: $imageDescriptor,
                creativeDirection: is_string($validated['creative_direction'] ?? null) ? $validated['creative_direction'] : null,
                traceId: $traceId,
            );

            $this->logEventImageUploadTrace($traceId, 'upload_completed', [
                'media_id' => $result->mediaId,
                'collection' => $result->mediaCollection,
                'mime_type' => $result->mimeType,
                'file_name' => $result->fileName,
            ]);

            return $this->eventImageUploadResponse($result);
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return $this->eventImageUploadInputSchema($schema);
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->eventImageUploadOutputSchema($schema);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return parent::toArray();
    }
}
