<?php

declare(strict_types=1);

namespace App\Features\References\Api;

/**
 * References publishes the contribution projection for reference subjects.
 *
 * Contributions resolves this contract by the persisted morph alias and
 * composes form contracts, current state and update validation without
 * importing the Reference model or private References schema.
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
