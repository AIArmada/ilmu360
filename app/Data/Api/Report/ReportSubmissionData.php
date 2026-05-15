<?php

namespace App\Data\Api\Report;

use App\Models\Report;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReportSubmissionData extends Data
{
    public function __construct(
        public string $id,
        /** @var list<array{id: int|string, name: string, file_name: string, mime_type: string|null, size: int, url: string}> */
        public array $evidence,
    ) {}

    public static function fromModel(Report $report): self
    {
        $evidence = $report->relationLoaded('media')
            ? $report->media->where('collection_name', 'evidence')->values()
            : $report->getMedia('evidence');

        return new self(
            id: (string) $report->id,
            evidence: $evidence
                ->map(fn (Media $media): array => [
                    'id' => $media->getKey(),
                    'name' => $media->name !== '' ? $media->name : $media->file_name,
                    'file_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => (int) $media->size,
                    'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
                ])
                ->all(),
        );
    }
}
