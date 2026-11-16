<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Models\InstitutionName;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep exactly one primary name per institution after any name save.
 */
final readonly class EnforceSinglePrimaryInstitutionNameAction
{
    public function handle(Model $name): void
    {
        MorphModelResolver::assert($name, 'institution_name');

        if (! $name->getAttribute('is_primary')) {
            return;
        }

        InstitutionName::query()
            ->where('institution_id', $name->getAttribute('institution_id'))
            ->whereKeyNot($name->getKey())
            ->update(['is_primary' => false]);
    }
}
