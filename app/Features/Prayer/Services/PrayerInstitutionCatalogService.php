<?php

declare(strict_types=1);

namespace App\Features\Prayer\Services;

use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Prayer\Api\PrayerInstitutionCatalog;

final readonly class PrayerInstitutionCatalogService implements PrayerInstitutionCatalog
{
    public function __construct(private InstitutionCatalog $institutions) {}

    public function verifiedInstitutions(string $search): array
    {
        return $this->institutions->prayerOptions($search);
    }
}
