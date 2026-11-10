<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Directory\DirectoryOption;

/**
 * Read boundary for Directory records used by forms, search and display.
 *
 * The reader never returns mutable Eloquent models. `find()` resolves either a
 * UUID or the record's public slug where the resource has one.
 */
interface DirectoryReader
{
    public function exists(DirectoryRecordType $type, string $identifier): bool;

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
}
