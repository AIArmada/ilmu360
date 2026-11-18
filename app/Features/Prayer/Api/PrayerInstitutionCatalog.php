<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

interface PrayerInstitutionCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function verifiedInstitutions(string $search): array;
}
