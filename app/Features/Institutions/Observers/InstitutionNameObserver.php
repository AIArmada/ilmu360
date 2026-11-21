<?php

declare(strict_types=1);

namespace App\Features\Institutions\Observers;

use App\Features\Institutions\Actions\EnforceSinglePrimaryInstitutionNameAction;
use App\Features\Institutions\Models\InstitutionName;

final readonly class InstitutionNameObserver
{
    public function __construct(private EnforceSinglePrimaryInstitutionNameAction $primaryName) {}

    public function saved(InstitutionName $name): void
    {
        $this->primaryName->handle($name);
    }
}
