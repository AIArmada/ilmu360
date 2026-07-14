<?php

declare(strict_types=1);

namespace App\Mcp\Prompts\Concerns;

use App\Models\Event;
use App\Support\Mcp\EventCoverPromptBuilder;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompts\Argument;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

trait BuildsEventImagePrompt
{
    /**
     * @return array<int, Argument>
     */
    protected function eventImagePromptArguments(): array
    {
        return [
            new Argument(
                name: 'event_key',
                description: 'Event UUID or slug/route key. Example: tadabbur-isu-semasa-ummah-qdkhqqn.',
                required: true,
            ),
            new Argument(
                name: 'creative_direction',
                description: 'Optional additional design direction such as mood, color preference, typography style, or what to emphasize.',
                required: false,
            ),
            new Argument(
                name: 'include_existing_media',
                description: 'Whether to include existing event media as reference images. Defaults to true.',
                required: false,
            ),
            new Argument(
                name: 'max_reference_media',
                description: 'Maximum number of reference images to include (0–8). Defaults to 4.',
                required: false,
            ),
        ];
    }

    /**
     * Build the array of MCP prompt messages for the given event and target collection.
     *
     * Returns the prompt text as a user message followed by reference images as individual
     * user messages, so ChatGPT can use them as input when generating the event image.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, Response>
     */
    protected function buildEventImagePromptMessages(Event $event, string $targetCollection, array $arguments): array
    {
        $builder = app(EventCoverPromptBuilder::class);

        $maxReferenceMedia = max(0, min(8, (int) ($arguments['max_reference_media'] ?? 4)));

        $result = $builder->build($event, [
            'target_collection' => $targetCollection,
            'creative_direction' => is_string($arguments['creative_direction'] ?? null) && trim($arguments['creative_direction']) !== ''
                ? trim($arguments['creative_direction'])
                : null,
            'include_existing_media' => $this->parseBoolArgument($arguments['include_existing_media'] ?? null, true),
            'max_reference_media' => $maxReferenceMedia,
        ]);

        $payload = $result['payload'];
        $contentMedia = array_slice($result['content_media'], 0, $maxReferenceMedia);

        $payload['reference_media'] = array_map(
            fn (array $mediaItem): array => $mediaItem['payload'],
            $contentMedia,
        );

        $promptText = $this->buildPromptMessageText($payload, $targetCollection);

        $messages = [Response::text($promptText)];

        foreach ($contentMedia as $mediaItem) {
            $imageResponse = $this->imageResponseForMedia($mediaItem['media']);

            if ($imageResponse instanceof Response) {
                $messages[] = $imageResponse;
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildPromptMessageText(array $payload, string $targetCollection): string
    {
        $prompt = is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '';
        $target = $this->canonicalTarget($targetCollection);
        $aspectRatio = $target['aspect_ratio'];
        $outputWidth = $target['output_width'];
        $outputHeight = $target['output_height'];
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        $eventTitle = is_string($event['title'] ?? null) ? $event['title'] : '';
        $eventKey = is_string($event['route_key'] ?? null) ? $event['route_key'] : '';

        $collectionLabel = $targetCollection === 'poster' ? 'marketing poster (4:5 portrait)' : 'website/mobile cover (16:9 landscape)';
        $forbiddenShape = $targetCollection === 'poster' ? '16:9 landscape cover' : '4:5 portrait poster/flyer';
        $uploadTool = $targetCollection === 'poster' ? 'upload-event-poster-image' : 'upload-event-cover-image';

        $safetyNotes = is_array($payload['usage']['safety_notes'] ?? null)
            ? implode("\n", array_map(fn (mixed $n): string => '- '.$n, $payload['usage']['safety_notes']))
            : '';

        $referenceNote = $this->referenceAssetsText($payload);

        return <<<TEXT
        Generate a {$collectionLabel} for the following event:

        {$prompt}

        ---
        **Output requirements:**
        - Target collection: `{$target['collection']}`
        - Aspect ratio: **{$aspectRatio}**
        - Recommended output size: {$outputWidth}×{$outputHeight} px
        - Treat this as a strict {$target['collection']} request; do **not** generate a {$forbiddenShape}
        - Do NOT add letterboxing or padding — fill the entire canvas

        **Safety rules:**
        {$safetyNotes}{$referenceNote}

        ---
        Once you have generated the image, use the **{$uploadTool}** tool to save it to the event:
        - event_key: `{$eventKey}`
        - event_title: {$eventTitle}
        TEXT;
    }

    /**
     * @return array{collection: 'cover'|'poster', aspect_ratio: string, output_width: int, output_height: int}
     */
    private function canonicalTarget(string $targetCollection): array
    {
        if ($targetCollection === 'poster') {
            return [
                'collection' => 'poster',
                'aspect_ratio' => '4:5',
                'output_width' => 1600,
                'output_height' => 2000,
            ];
        }

        return [
            'collection' => 'cover',
            'aspect_ratio' => '16:9',
            'output_width' => 1600,
            'output_height' => 900,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function referenceAssetsText(array $payload): string
    {
        $referenceMedia = is_array($payload['reference_media'] ?? null) ? $payload['reference_media'] : [];

        if ($referenceMedia === []) {
            return '';
        }

        $lines = [
            '',
            'Reference images are attached to this message when supported by the client. Use them for brand/style/identity consistency.',
            'If your client does not expose inline image attachments, use these fallback reference assets:',
        ];

        foreach ($referenceMedia as $index => $media) {
            if (! is_array($media)) {
                continue;
            }

            $position = $index + 1;
            $label = is_string($media['label'] ?? null) && trim($media['label']) !== ''
                ? trim($media['label'])
                : (is_string($media['file_name'] ?? null) && trim($media['file_name']) !== ''
                    ? trim($media['file_name'])
                    : "Reference asset {$position}");
            $role = is_string($media['role'] ?? null) && trim($media['role']) !== ''
                ? trim($media['role'])
                : 'reference';
            $collection = is_string($media['collection'] ?? null) && trim($media['collection']) !== ''
                ? trim($media['collection'])
                : 'unknown';
            $reason = is_string($media['selection_reason'] ?? null) && trim($media['selection_reason']) !== ''
                ? trim($media['selection_reason'])
                : 'Use if helpful.';
            $url = is_string($media['url'] ?? null) && trim($media['url']) !== ''
                ? trim($media['url'])
                : (is_string($media['original_url'] ?? null) && trim($media['original_url']) !== ''
                    ? trim($media['original_url'])
                    : null);

            $urlLine = $url ?? 'no direct URL available; rely on the inline attachment if present';

            $lines[] = "{$position}. {$label} [role: {$role}; collection: {$collection}] — {$reason} — asset: {$urlLine}";
        }

        return "\n\n".implode("\n", $lines);
    }

    private function imageResponseForMedia(Media $media): ?Response
    {
        $mimeType = is_string($media->mime_type) ? $media->mime_type : null;

        if ($mimeType === null || ! str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $contents = $this->loadMediaContents($media);

        if ($contents === null) {
            return null;
        }

        return Response::image($contents, $mimeType);
    }

    private function loadMediaContents(Media $media): ?string
    {
        $relativePath = $media->getPathRelativeToRoot();
        $disk = is_string($media->disk) ? $media->disk : null;

        if ($relativePath !== '' && is_string($disk) && $disk !== '') {
            try {
                if (Storage::disk($disk)->exists($relativePath)) {
                    $contents = Storage::disk($disk)->get($relativePath);

                    if (is_string($contents) && $contents !== '') {
                        return $contents;
                    }
                }
            } catch (Throwable) {
                // Fall through to local path fallback.
            }
        }

        $path = $media->getPath();

        if ($path !== '' && is_file($path)) {
            $contents = file_get_contents($path);

            if (is_string($contents) && $contents !== '') {
                return $contents;
            }
        }

        return null;
    }

    private function parseBoolArgument(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
        }

        return $default;
    }
}
