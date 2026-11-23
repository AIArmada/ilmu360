<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Media\StagedUpload;

final readonly class UploadEventImageInput
{
    public function __construct(
        public string $actorId,
        public string $eventId,
        public EventImageCollection $collection,
        public StagedUpload $file,
        public ?string $creativeDirection = null,
        public ?string $sourceFileId = null,
    ) {}
}
