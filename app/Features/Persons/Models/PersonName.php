<?php

declare(strict_types=1);

namespace App\Features\Persons\Models;

use AIArmada\Persons\Models\PersonName as BasePersonName;

/**
 * Person name with an explicit literal table for Directory-owned writes.
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
