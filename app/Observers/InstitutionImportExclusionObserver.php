<?php

declare(strict_types=1);

namespace App\Observers;

use App\Features\Directory\Api\InstitutionWorkflow;
use App\Models\Institution;

/**
 * Synchronously records source-backed institution deletions as permanent
 * import exclusions.
 *
 * Intentionally synchronous (no ShouldHandleEventsAfterCommit): the exclusion
 * is written in the same transaction as the deletion, so a rolled-back
 * delete never leaves a stray exclusion and a committed delete is always
 * protected before any later import can observe it.
 *
 * Source identity bytes are preserved exactly as stored: no trimming,
 * case rewrites, or other normalization, matching the exclusion lookup.
 * Only rows carrying both source and external_ref are recorded; manually
 * created rows without provenance need no exclusion because no feed row
 * can match them. Related venues and spaces are never touched.
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

        app(InstitutionWorkflow::class)->recordImportExclusion(
            $source,
            $externalRef,
            (string) $institution->getKey(),
        );
    }
}
