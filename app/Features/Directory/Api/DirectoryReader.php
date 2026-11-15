<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Directory\DirectoryOption;

/**
 * Read boundary for Directory records used by forms, search, display and route-key resolution.
 *
 * The reader never returns mutable Eloquent models. `find()` resolves either a
 * UUID or the record's public slug where the resource has one.
 */
interface DirectoryReader
{
    public function exists(DirectoryRecordType $type, string $identifier): bool;

    /**
     * Resolve a UUID or public slug to the record UUID.
     */
    public function resolveIdentifier(DirectoryRecordType $type, string $identifier): ?string;

    public function find(DirectoryRecordType $type, string $identifier): ?DirectoryDetail;

    /**
     * Selectable options for form pickers.
     *
     * @param  string|null  $accountId  Submitted-by account scope for institutions and speakers.
     * @param  string|null  $institutionId  Institution scope for spaces.
     * @return list<DirectoryOption>
     */
    public function options(
        DirectoryRecordType $type,
        ?string $search = null,
        int $limit = 50,
        ?string $accountId = null,
        ?string $institutionId = null,
    ): array;

    /**
     * @return list<DirectoryOption>
     */
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
}
