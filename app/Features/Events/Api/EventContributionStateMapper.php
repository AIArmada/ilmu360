<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Contribution form-state normalisation boundary for event subjects.
 *
 * The object parameters are the hydrated event or subject model; the owner
 * implementation narrows them to its own model types.
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
    public function toPersistenceState(array $state, ?object $event = null): array;

    /**
     * @return array<string, mixed>
     */
    public function provenanceOriginals(object $entity): array;

    /**
     * @param  array<string, mixed>  $formState
     */
    public function effectiveOptionsCountry(array $formState, ?object $event = null): string;
}
