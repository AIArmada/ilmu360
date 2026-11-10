<?php

declare(strict_types=1);

namespace App\Models;

use AIArmada\Persons\Models\PersonName as BasePersonName;

/**
 * Person name with an explicit literal table so Directory write ownership is
 * enforced while the model remains in the legacy root namespace.
 */
class PersonName extends BasePersonName
{
    #[\Override]
    protected $table = 'person_names';

    #[\Override]
    public function getTable(): string
    {
        return $this->table;
    }
}
