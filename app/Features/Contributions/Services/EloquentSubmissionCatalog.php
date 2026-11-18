<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\SubmissionCatalog;
use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Persons\Api\PersonCatalog;

final readonly class EloquentSubmissionCatalog implements SubmissionCatalog
{
    public function __construct(
        private InstitutionCatalog $institutions,
        private PersonCatalog $persons,
    ) {}

    public function institutions(?string $accountId, ?string $search = null, int $limit = 50): array
    {
        return $this->institutions->submissionOptions($accountId, $search, $limit);
    }

    public function persons(?string $accountId, ?string $search = null, int $limit = 50): array
    {
        return $this->persons->submissionOptions($accountId, $search, $limit);
    }
}
