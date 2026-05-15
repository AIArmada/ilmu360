<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use App\Support\Mcp\EventImageGenerationService;
use Illuminate\Http\Request;

class GenerateEventCoverImageAction
{
    public function __construct(
        private readonly EventImageGenerationService $eventImageGenerationService,
        private readonly ProductSignalsService $productSignalsService,
    ) {}

    /**
     * @return array{
     *     payload: array<string, mixed>,
     *     image_contents: string,
     *     image_mime_type: string
     * }
     */
    public function handle(
        Event $event,
        ?string $creativeDirection = null,
        bool $includeExistingMedia = true,
        ?int $maxReferenceMedia = null,
        ?User $user = null,
        ?Request $request = null,
    ): array {
        $maxReferenceMedia ??= $this->defaultMaxReferenceMedia();

        $result = $this->eventImageGenerationService->generate($event, 'cover', [
            'creative_direction' => $creativeDirection,
            'include_existing_media' => $includeExistingMedia,
            'max_reference_media' => $maxReferenceMedia,
        ]);

        $freshEvent = $event->fresh();

        $this->productSignalsService->recordEventCoverGenerated(
            event: $freshEvent instanceof Event ? $freshEvent : $event,
            user: $user,
            request: $request,
            properties: [
                'media_id' => data_get($result, 'payload.generated_media.id'),
                'provider' => data_get($result, 'payload.generation.provider'),
                'model' => data_get($result, 'payload.generation.model'),
                'quality' => data_get($result, 'payload.generation.quality'),
                'attached_reference_media_count' => data_get($result, 'payload.generation.attached_reference_media_count'),
            ],
        );

        return $result;
    }

    private function defaultMaxReferenceMedia(): int
    {
        return max(0, min(8, (int) config('ai.features.event_cover_generation.max_reference_media', 6)));
    }
}
