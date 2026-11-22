<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Media\MediaChangeListener;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class RecordingMediaChangeListener implements MediaChangeListener
{
    /** @var list<string> */
    public array $changes = [];

    public function mediaChanged(Media $media, string $change): void
    {
        $this->changes[] = $change;
    }
}
