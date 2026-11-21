<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions\Imports;

use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;

/**
 * Record the source identity synchronously with the institution deletion.
 * A rolled-back deletion must never leave a permanent import exclusion.
 */
final readonly class RecordInstitutionImportExclusionAction
{
    public function __construct(private InstitutionWorkflow $workflow) {}

    public function handle(Institution $institution): void
    {
        $source = $institution->getAttribute('source');
        $externalRef = $institution->getAttribute('external_ref');

        if (! is_string($source) || $source === '' || ! is_string($externalRef) || $externalRef === '') {
            return;
        }

        $this->workflow->recordImportExclusion(
            $source,
            $externalRef,
            (string) $institution->getKey(),
        );
    }
}
