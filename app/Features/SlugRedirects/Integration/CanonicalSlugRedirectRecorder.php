<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Integration;

use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use AIArmada\CommerceSupport\Data\RecordReference;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;

final readonly class CanonicalSlugRedirectRecorder implements SlugRedirectRecorder
{
    public function __construct(
        private SlugRedirectCommands $slugRedirectCommands,
    ) {}

    public function record(RecordReference $record, ?string $previousSlug, string $currentSlug): bool
    {
        return $this->slugRedirectCommands->record(
            $record->type,
            $record->id,
            $currentSlug,
            $previousSlug,
        );
    }
}
