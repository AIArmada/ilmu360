<?php

declare(strict_types=1);

namespace App\Contracts\ShareCards;

/**
 * Capability report for the leaf renderer on this machine.
 *
 * readiness() is the only place that performs the expensive full font-hash
 * verification; render() performs cheap presence checks and reports the
 * same unavailability as an error result. A non-ready report must route to
 * the honest cover fallback, never to a generated-card claim.
 */
final readonly class ShareCardReadiness
{
    /**
     * @param  array<string, string>  $families  Font family name => absolute file path.
     * @param  list<string>  $missing  Human-readable missing/invalid capability entries.
     * @param  list<string>  $notes  Non-fatal observations (fallbacks, version details).
     */
    public function __construct(
        public bool $ready,
        public string $fontDirectory,
        public ?string $pangoBinary,
        public ?string $pangoVersion,
        public string $fontRevision,
        public array $families = [],
        public array $missing = [],
        public array $notes = [],
    ) {}
}
