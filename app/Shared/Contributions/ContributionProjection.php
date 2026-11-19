<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

interface ContributionProjection
{
    public function supports(string $entityType): bool;

    /** @return array<string, mixed> */
    public function contractFor(string $subjectId): array;

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array;

    /** @return array<string, mixed> */
    public function updateRulesFor(string $subjectId): array;
}
