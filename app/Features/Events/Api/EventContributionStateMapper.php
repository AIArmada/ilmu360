<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Contribution form-state normalisation boundary for event subjects.
 *
 *
 * External callers pass identifiers; Events resolves its own models.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Services, Contributions/EntryPoints
 */
interface EventContributionStateMapper
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toHelperState(array $state): array;

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toPersistenceState(array $state, ?string $eventId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function provenanceOriginals(?string $eventId = null): array;
}
