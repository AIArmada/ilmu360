<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionImportExclusion;

/**
 * Record the durable import exclusion for a deleted source-backed institution.
 */
final readonly class RecordInstitutionImportExclusionAction
{
    public function handleDeletion(Institution $institution): void
    {
        $source = $institution->getAttribute('source');
        $externalRef = $institution->getAttribute('external_ref');

        if (! is_string($source) || $source === '' || ! is_string($externalRef) || $externalRef === '') {
            return;
        }

        $this->handle($source, $externalRef, (string) $institution->getKey());
    }

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
