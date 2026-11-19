<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

use App\Features\Reports\Api\ReportSubject;

interface InstitutionLookup
{
    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?InstitutionSummary;

    /** @return list<\App\Shared\Directory\DirectoryOption> */
    public function searchPrayerInstitutions(string $search, int $limit = 50): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function prayerInstitutionLabels(array $institutionIds): array;

    /**
     * @param  list<string>  $institutionIds
     * @return list<string>
     */
    public function selectablePrayerInstitutionIds(array $institutionIds): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string|null>
     */
    public function rawNamesByIds(array $institutionIds): array;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;

    /** @return list<string> */
    public function publicSearchIds(string $search): array;

    public function institutionMorphType(): string;
}
