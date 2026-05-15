<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image as ImageAttachment;
use Laravel\Ai\Image;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class EventImageGenerationService
{
    public function __construct(
        private readonly EventCoverPromptBuilder $promptBuilder,
    ) {}

    /**
     * @param  array{
     *   creative_direction?: string|null,
     *   include_existing_media?: bool|null,
     *   max_reference_media?: int|null
     * }  $options
     * @return array{
     *   payload: array<string, mixed>,
     *   image_contents: string,
     *   image_mime_type: string
     * }
     */
    public function generate(Event $event, string $targetCollection, array $options = []): array
    {
        $result = $this->promptBuilder->build($event, [
            'target_collection' => $targetCollection,
            'creative_direction' => $options['creative_direction'] ?? null,
            'include_existing_media' => $options['include_existing_media'] ?? true,
            'max_reference_media' => $options['max_reference_media'] ?? null,
        ]);

        $payload = $result['payload'];
        $target = $this->targetFromPayload($payload);
        $maxReferenceMedia = max(0, min(8, (int) ($options['max_reference_media'] ?? 6)));
        $attachments = $this->attachments($result['content_media'], $maxReferenceMedia);
        $provider = $this->generationProvider();
        $model = $this->generationModel();
        $quality = $this->generationQuality();
        $timeout = $this->generationTimeout();

        $pendingImage = Image::of((string) $payload['prompt'])
            ->attachments($attachments)
            ->quality($quality)
            ->timeout($timeout);

        $imageResponse = $target['collection'] === 'poster'
            ? $pendingImage->portrait()->generate($provider, $model)
            : $pendingImage->landscape()->generate($provider, $model);

        $normalizedImage = $this->normalizeToTargetRatio(
            contents: (string) $imageResponse,
            outputWidth: $target['output_width'],
            outputHeight: $target['output_height'],
        );

        $media = $this->storeGeneratedMedia(
            event: $event,
            target: $target,
            image: $normalizedImage,
            prompt: (string) $payload['prompt'],
            attachmentCount: count($attachments),
            provider: $imageResponse->meta->provider,
            model: $imageResponse->meta->model,
            quality: $quality,
        );

        $attachedMediaIds = collect(array_slice($result['content_media'], 0, $maxReferenceMedia))
            ->pluck('payload.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $payload['reference_media'] = array_map(
            function (array $mediaPayload) use ($attachedMediaIds): array {
                $mediaPayload['attached_to_generation_request'] = in_array((string) ($mediaPayload['id'] ?? ''), $attachedMediaIds, true);

                return $mediaPayload;
            },
            is_array($payload['reference_media'] ?? null) ? $payload['reference_media'] : [],
        );
        $payload['generated_media'] = $this->generatedMediaPayload($media, $target);
        $payload['generation'] = [
            'provider' => $imageResponse->meta->provider,
            'model' => $imageResponse->meta->model,
            'quality' => $quality,
            'requested_ai_size' => $target['ai_size'],
            'normalized_mime_type' => $normalizedImage['mime_type'],
            'attached_reference_media_count' => count($attachments),
            'max_reference_media' => $maxReferenceMedia,
            'usage' => $imageResponse->usage->toArray(),
        ];

        return [
            'payload' => $payload,
            'image_contents' => $normalizedImage['contents'],
            'image_mime_type' => $normalizedImage['mime_type'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }
     */
    private function targetFromPayload(array $payload): array
    {
        $target = is_array($payload['target'] ?? null) ? $payload['target'] : [];
        $collection = ($target['collection'] ?? null) === 'poster' ? 'poster' : 'cover';

        if ($collection === 'poster') {
            return [
                'collection' => 'poster',
                'label' => 'Event Poster Image',
                'aspect_ratio' => '4:5',
                'ratio_width' => 4,
                'ratio_height' => 5,
                'ai_size' => '2:3',
                'output_width' => 1600,
                'output_height' => 2000,
            ];
        }

        return [
            'collection' => 'cover',
            'label' => 'Event Cover Image',
            'aspect_ratio' => '16:9',
            'ratio_width' => 16,
            'ratio_height' => 9,
            'ai_size' => '3:2',
            'output_width' => 1600,
            'output_height' => 900,
        ];
    }

    /**
     * @param  list<array{media: Media, payload: array<string, mixed>}>  $mediaItems
     * @return list<ImageAttachment>
     */
    private function attachments(array $mediaItems, int $maxReferenceMedia): array
    {
        $attachments = [];

        foreach (array_slice($mediaItems, 0, $maxReferenceMedia) as $mediaItem) {
            $attachment = $this->attachmentForMedia($mediaItem['media']);

            if ($attachment instanceof ImageAttachment) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    private function attachmentForMedia(Media $media): ?ImageAttachment
    {
        $mimeType = is_string($media->mime_type) ? $media->mime_type : null;

        if ($mimeType === null || ! str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $relativePath = $media->getPathRelativeToRoot();
        $disk = is_string($media->disk) ? $media->disk : null;

        if ($relativePath !== '' && is_string($disk) && $disk !== '') {
            try {
                if (Storage::disk($disk)->exists($relativePath)) {
                    return ImageAttachment::fromStorage($relativePath, $disk);
                }
            } catch (Throwable) {
                // Skip unsupported storage adapters and continue with local path fallback.
            }
        }

        $path = $media->getPath();

        if ($path !== '' && is_file($path)) {
            return ImageAttachment::fromPath($path, $mimeType);
        }

        return null;
    }

    /**
     * @param  array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }  $target
     * @param  array{
     *   contents: string,
     *   mime_type: string,
     *   extension: string,
     *   width: int,
     *   height: int,
     *   source_width: int,
     *   source_height: int
     * }  $image
     */
    private function storeGeneratedMedia(
        Event $event,
        array $target,
        array $image,
        string $prompt,
        int $attachmentCount,
        ?string $provider,
        ?string $model,
        string $quality,
    ): Media {
        $fileName = $this->generatedFileName($event, $target['collection'], $image['extension']);

        return $event
            ->addMediaFromString($image['contents'])
            ->usingFileName($fileName)
            ->usingName($target['label'].' - '.((string) $event->title !== '' ? (string) $event->title : $fileName))
            ->withCustomProperties([
                'collection' => $target['collection'],
                'original_file_name' => $fileName,
                'source' => 'mcp_generated',
                'required_aspect_ratio' => $target['aspect_ratio'],
                'source_dimensions' => [
                    'width' => $image['width'],
                    'height' => $image['height'],
                ],
                'generation' => [
                    'prompt_sha256' => hash('sha256', $prompt),
                    'provider' => $provider,
                    'model' => $model,
                    'quality' => $quality,
                    'attached_reference_media_count' => $attachmentCount,
                ],
            ])
            ->toMediaCollection($target['collection']);
    }

    private function generatedFileName(Event $event, string $collection, string $extension): string
    {
        $base = Str::slug((string) ($event->slug ?: $event->title ?: 'event'));
        $suffix = Str::lower(substr((string) Str::ulid(), -8));

        return "{$base}-{$collection}-{$suffix}.{$extension}";
    }

    /**
     * @return array{
     *   contents: string,
     *   mime_type: string,
     *   extension: string,
     *   width: int,
     *   height: int,
     *   source_width: int,
     *   source_height: int
     * }
     */
    private function normalizeToTargetRatio(string $contents, int $outputWidth, int $outputHeight): array
    {
        $dimensions = @getimagesizefromstring($contents);

        if (! is_array($dimensions)) {
            throw new RuntimeException('The generated image could not be decoded.');
        }

        $sourceWidth = $dimensions[0];
        $sourceHeight = $dimensions[1];

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new RuntimeException('The generated image dimensions are invalid.');
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof \GdImage) {
            throw new RuntimeException('The generated image could not be processed.');
        }

        $targetRatio = $outputWidth / $outputHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) floor($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) floor($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        $canvas = imagecreatetruecolor($outputWidth, $outputHeight);

        if (! $canvas instanceof \GdImage) {
            imagedestroy($source);

            throw new RuntimeException('The generated image could not be normalized.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $outputWidth, $outputHeight, $transparent);
        }

        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            $outputWidth,
            $outputHeight,
            $cropWidth,
            $cropHeight,
        );

        imagedestroy($source);

        ob_start();
        $encoded = function_exists('imagewebp')
            ? imagewebp($canvas, null, 92)
            : imagepng($canvas);
        $normalizedContents = ob_get_clean();
        imagedestroy($canvas);

        if (! $encoded || ! is_string($normalizedContents) || $normalizedContents === '') {
            throw new RuntimeException('The generated image could not be encoded.');
        }

        return [
            'contents' => $normalizedContents,
            'mime_type' => function_exists('imagewebp') ? 'image/webp' : 'image/png',
            'extension' => function_exists('imagewebp') ? 'webp' : 'png',
            'width' => $outputWidth,
            'height' => $outputHeight,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
        ];
    }

    /**
     * @param  array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }  $target
     * @return array<string, mixed>
     */
    private function generatedMediaPayload(Media $media, array $target): array
    {
        return [
            'id' => (string) $media->getKey(),
            'uuid' => (string) $media->uuid,
            'collection' => (string) $media->collection_name,
            'name' => (string) $media->name,
            'file_name' => (string) $media->file_name,
            'mime_type' => (string) $media->mime_type,
            'size_bytes' => (int) $media->size,
            'url' => $media->getUrl(),
            'required_aspect_ratio' => $target['aspect_ratio'],
            'width' => $target['output_width'],
            'height' => $target['output_height'],
            'conversions' => [
                'thumb' => $media->getAvailableUrl(['thumb']),
                'card' => $media->getAvailableUrl(['card']),
                'preview' => $media->getAvailableUrl(['preview']),
            ],
        ];
    }

    private function generationProvider(): string
    {
        $provider = config('ai.features.event_cover_generation.provider', 'openai');

        return is_string($provider) && trim($provider) !== ''
            ? trim($provider)
            : 'openai';
    }

    private function generationModel(): string
    {
        $model = config('ai.features.event_cover_generation.model', 'gpt-image-2');

        return is_string($model) && trim($model) !== ''
            ? trim($model)
            : 'gpt-image-2';
    }

    private function generationQuality(): string
    {
        $quality = config('ai.features.event_cover_generation.quality', 'low');

        if (! is_string($quality)) {
            return 'low';
        }

        $quality = trim($quality);

        return in_array($quality, ['low', 'medium', 'high'], true) ? $quality : 'low';
    }

    private function generationTimeout(): int
    {
        $timeout = config('ai.features.event_cover_generation.timeout', 120);

        return max(1, (int) $timeout);
    }
}
