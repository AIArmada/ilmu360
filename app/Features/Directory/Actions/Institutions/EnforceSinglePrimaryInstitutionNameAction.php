<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep exactly one primary name per institution after any name save.
 */
final readonly class EnforceSinglePrimaryInstitutionNameAction
{
    public function handle(Model $name): void
    {
        DirectoryModel::assert($name, 'institution_name');

        if (! $name->getAttribute('is_primary')) {
            return;
        }

        DirectoryModel::query('institution_name')
            ->where('institution_id', $name->getAttribute('institution_id'))
            ->whereKeyNot($name->getKey())
            ->update(['is_primary' => false]);
    }
}
