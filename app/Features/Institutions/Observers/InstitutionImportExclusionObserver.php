<?php

declare(strict_types=1);

namespace App\Features\Institutions\Observers;

use App\Features\Institutions\Actions\Imports\RecordInstitutionImportExclusionAction;
use App\Features\Institutions\Models\Institution;

/** Synchronous deletion hook; the Action participates in the caller transaction. */
final readonly class InstitutionImportExclusionObserver
{
    public function __construct(private RecordInstitutionImportExclusionAction $exclusions) {}

    public function deleted(Institution $institution): void
    {
        $this->exclusions->handle($institution);
    }
}
