<?php

declare(strict_types=1);

namespace App\Contracts\ShareCards;

/**
 * Explicit render input for one share card.
 *
 * All display strings are canonical values supplied by the future presenter
 * adapter: the renderer performs no event, prayer, geography, timezone or
 * claim-source computation, and no translation lookup. Locale only selects
 * shaping base direction, alignment, language hint and base font family.
 *
 * Privacy contract: this struct has no field for per-user tokens, guest
 * credentials, visitor ids, private counts or generic metadata, and none
 * may be added without breaking this contract on purpose.
 */
final readonly class ShareCardRenderInput
{
    public function __construct(
        public string $title,
        public string $locale,
        public string $brandLine,
        public string $scheduleLabel,
        public ?string $venueLabel = null,
        public ?string $speakerLabel = null,
        public ?string $verifiedLabel = null,
        public ?string $statusLabel = null,
        public ?string $coverImageBytes = null,
    ) {}
}
