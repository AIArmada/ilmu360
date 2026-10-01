<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Institution;
use App\Models\InstitutionImportExclusion;

/**
 * Synchronously records source-backed institution deletions as permanent
 * import exclusions.
 *
 * Intentionally synchronous (no ShouldHandleEventsAfterCommit): the exclusion
 * is written in the same transaction as the deletion, so a rolled-back
 * delete never leaves a stray exclusion and a committed delete is always
 * protected before any later reseed can observe it.
 *
 * Source identity bytes are preserved exactly as stored: no trimming,
 * case rewrites, or other normalization, matching the DTO, importer, and
 * exclusion lookup. Only rows carrying both source and external_ref are
 * recorded; manually created rows without provenance need no exclusion
 * because no feed row can match them. Related venues and spaces are never
 * touched.
 */
class InstitutionImportExclusionObserver
{
    public function deleted(Institution $institution): void
    {
        $source = $institution->getAttribute('source');
        $externalRef = $institution->getAttribute('external_ref');

        if (! is_string($source) || $source === '' || ! is_string($externalRef) || $externalRef === '') {
            return;
        }

        InstitutionImportExclusion::query()->firstOrCreate(
            [
                'source' => $source,
                'external_ref' => $externalRef,
            ],
            [
                'institution_id' => (string) $institution->getKey(),
                'deleted_at' => now(),
            ],
        );
    }
}
