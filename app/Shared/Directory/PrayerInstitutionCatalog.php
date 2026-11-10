<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Selectable prayer-institution options for account preference forms.
 *
 * Directory owns the institution reads once extracted; this interim boundary
 * preserves the current active/verified selection rules.
 */
interface PrayerInstitutionCatalog
{
    /**
     * @return list<DirectoryOption>
     */
    public function search(string $search, int $limit = 50): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function labels(array $institutionIds): array;

    /**
     * @param  list<string>  $institutionIds
     * @return list<string>
     */
    public function selectableIds(array $institutionIds): array;
}
