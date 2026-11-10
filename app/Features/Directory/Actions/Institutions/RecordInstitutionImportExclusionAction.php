<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;

/**
 * Record the durable import exclusion for a deleted source-backed institution.
 */
final readonly class RecordInstitutionImportExclusionAction
{
    public function handle(string $source, string $externalRef, string $institutionId): void
    {
        DirectoryModel::query('institution_import_exclusion')->firstOrCreate(
            [
                'source' => $source,
                'external_ref' => $externalRef,
            ],
            [
                'institution_id' => $institutionId,
                'deleted_at' => now(),
            ],
        );
    }
}
