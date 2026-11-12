<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Event-owned staged contribution media application.
 *
 * Contribution approvals hand media staged on the pending request to the
 * event through this boundary. The Platform media operation owns the
 * collection write chain, and Directory owns donation-QR attachment.
 */
interface EventMedia
{
    /**
     * Apply media staged on a pending contribution request to an event:
     * replace single-file collections, append multi-file collections, and
     * discard the staged copies.
     *
     * @param  iterable<int, Media>  $stagedMedia
     */
    public function applyStagedMedia(HasMedia $event, iterable $stagedMedia): void;
}
