<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Models\InstitutionImportExclusion;

/**
 * Record the durable import exclusion for a deleted source-backed institution.
 */
final readonly class RecordInstitutionImportExclusionAction
{
    public function handle(string $source, string $externalRef, string $institutionId): void
    {
        InstitutionImportExclusion::query()->firstOrCreate(
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
