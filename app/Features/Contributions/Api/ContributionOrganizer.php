<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/**
 * Opaque primary-organizer identity for the event submission pipeline.
 *
 * The submission context resolves institution-vs-person precedence once and
 * carries the decision as a value so no foreign Eloquent model crosses the
 * boundary.
 */
final readonly class ContributionOrganizer
{
    public function __construct(
        public string $kind,
        public string $id,
    ) {}
}
