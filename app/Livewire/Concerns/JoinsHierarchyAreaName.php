<?php

namespace App\Livewire\Concerns;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait JoinsHierarchyAreaName
{
    /**
     * @param  HasMany<AddressAreaAssignment, Address>  $relation
     * @return HasMany<AddressAreaAssignment, Address>
     */
    private function joinAreaName(HasMany $relation): HasMany
    {
        $assignmentsTable = (new AddressAreaAssignment)->getTable();
        $areasTable = config('addressing.tables.address_areas', 'address_areas');

        return $relation
            ->addSelect("{$assignmentsTable}.*")
            ->leftJoin($areasTable, "{$assignmentsTable}.address_area_id", '=', "{$areasTable}.id")
            ->addSelect("{$areasTable}.name as hierarchy_area_name")
            ->addSelect("{$areasTable}.type as hierarchy_area_type");
    }
}
