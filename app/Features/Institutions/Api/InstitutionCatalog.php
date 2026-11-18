<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

interface InstitutionCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function membershipApplicationSubjects(string $search): array;

    /** @return list<array{id: string, label: string}> */
    public function prayerOptions(string $search): array;

    /** @return array<string, string> */
    public function roleOptions(): array;

    /** @return list<array{id: string, label: string}> */
    public function submissionOptions(?string $accountId, ?string $search = null, int $limit = 50): array;
}
