<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Mcp\Tools\Concerns\UploadsEventImage;
use App\Models\Event;
use App\Support\Api\Member\MemberResourceRegistry;
use App\Support\Mcp\EventImageUploadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
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
class MemberUploadEventPosterImageTool extends AbstractMemberTool
{
    use UploadsEventImage;

    protected string $name = 'member-upload-event-poster-image';

    protected string $title = 'Upload Event Poster Image';

    protected string $description = 'Upload and save a 4:5 portrait marketing poster for an accessible Ahli event. Accepts image descriptors via {content_base64} or {content_url}. Use the member-event-poster-image-prompt to get the recommended prompt and reference images before generating.';

    public function __construct(
        private readonly MemberResourceRegistry $registry,
        private readonly EventImageUploadService $uploadService,
    ) {
        $this->setMeta([
            'openai/toolInvocation/invoking' => 'Uploading event poster image...',
            'openai/toolInvocation/invoked' => 'Event poster image uploaded.',
            'openai/note' => 'Pass {content_base64, filename} or {content_url, filename}. Required aspect ratio: 4:5 portrait.',
        ]);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $traceId = $this->startEventImageUploadTrace($request, $this->name);

        return $this->safeResponse(function () use ($request, $traceId): ResponseFactory {
            $actor = $this->authorizeMember($request);

            $this->logEventImageUploadTrace($traceId, 'authorized', [
                'actor_id' => $actor->getKey(),
                'actor_type' => 'member',
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

            $event = $this->resolveEvent((string) $validated['event_key']);

            $this->logEventImageUploadTrace($traceId, 'event_resolved', [
                'event_found' => $event instanceof Event,
                'event_id' => $event instanceof Event ? $event->getKey() : null,
                'event_slug' => $event instanceof Event ? $event->slug : null,
            ]);

            abort_unless($event instanceof Event, 404);

            $imageDescriptor = $this->normalizeImageDescriptor($validated['image'], $traceId);
            $this->enforceEventDescriptorHasContentSource($imageDescriptor);

            $this->logEventImageUploadTrace($traceId, 'descriptor_normalized', [
                'descriptor_keys' => array_keys($imageDescriptor),
                'has_download_url' => isset($imageDescriptor['download_url']) || isset($imageDescriptor['downloadUrl']),
                'has_content_base64' => isset($imageDescriptor['content_base64']) || isset($imageDescriptor['contentBase64']) || isset($imageDescriptor['base64']) || isset($imageDescriptor['data']),
                'has_file_id' => isset($imageDescriptor['file_id']) || isset($imageDescriptor['fileId']),
            ]);

            $media = $this->uploadService->upload(
                event: $event,
                collection: 'poster',
                descriptor: $imageDescriptor,
                creativeDirection: is_string($validated['creative_direction'] ?? null) ? $validated['creative_direction'] : null,
                traceId: $traceId,
            );

            $this->logEventImageUploadTrace($traceId, 'upload_completed', [
                'media_id' => $media->getKey(),
                'collection' => $media->collection_name,
                'mime_type' => $media->mime_type,
                'file_name' => $media->file_name,
            ]);

            return $this->eventImageUploadResponse($event, $media, 'poster');
        });
    }

    private function resolveEvent(string $eventKey): ?Event
    {
        $resourceClass = $this->registry->resolve('events');

        abort_unless(is_string($resourceClass) && $this->registry->canAccessResource($resourceClass), 404);

        $query = $this->registry->queryFor($resourceClass);
        $model = $query->getModel();

        $record = $query
            ->where(function (Builder $query) use ($model, $eventKey): void {
                $query
                    ->where($model->qualifyColumn($model->getRouteKeyName()), $eventKey)
                    ->orWhere($model->qualifyColumn('slug'), $eventKey);
            })
            ->first();

        return $record instanceof Event ? $record : null;
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
