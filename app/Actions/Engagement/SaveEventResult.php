<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Models\Bookmark;

/**
 * Immutable outcome of one save attempt.
 *
 * `created` reports whether this call created a new bookmark row; a replayed
 * save returns the existing/restored row with `created = false`. The bookmark
 * stays nullable so transports keep their existing "save could not be
 * resolved" branch without importing the package model themselves.
 */
final readonly class SaveEventResult
{
    public function __construct(
        public ?Bookmark $bookmark,
        public bool $created,
    ) {}
}
