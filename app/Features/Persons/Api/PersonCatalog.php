<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

interface PersonCatalog
{
    /** @return list<array{id: string, label: string, slug: string}> */
    public function membershipApplicationSubjects(string $search): array;

    /** @return list<array{id: string, label: string}> */
    public function submissionOptions(?string $accountId, ?string $search = null, int $limit = 50): array;
}
