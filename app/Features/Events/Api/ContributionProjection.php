<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Events publishes the contribution projection for event subjects.
 *
 * Contributions resolves this contract by the persisted morph alias and
 * composes form contracts, current state and update validation without
 * importing the Event model or private Events schema.
 */
interface ContributionProjection
{
    /**
     * Exact existing form/schema wire fields.
     *
     * @return array<string, mixed>
     */
    public function contractFor(string $subjectId): array;

    /**
     * Exact existing state wire fields.
     *
     * @return array<string, mixed>
     */
    public function stateFor(string $subjectId): array;

    /**
     * @return array<string, mixed>
     */
    public function updateRulesFor(string $subjectId): array;
}
