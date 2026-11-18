<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

interface SubmissionCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function institutions(?string $accountId, ?string $search = null, int $limit = 50): array;

    /** @return list<array{id: string, label: string}> */
    public function persons(?string $accountId, ?string $search = null, int $limit = 50): array;
}
