<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Filament;

use App\Shared\Auth\AccountActor;

/**
 * Per-request inputs for the canonical public event form definition.
 *
 * Entrypoints pass scalar identifiers and the already-authorized actor; the
 * Events feature resolves its own models, options and facts.
 */
final readonly class EventContributionFormContext
{
    public function __construct(
        public EventContributionFormMode $mode,
        public ?string $eventId = null,
        public ?string $scopedInstitutionId = null,
        public ?string $progressUpdateJs = null,
        public bool $hasEventContainer = false,
        public ?AccountActor $submitter = null,
    ) {}
}
