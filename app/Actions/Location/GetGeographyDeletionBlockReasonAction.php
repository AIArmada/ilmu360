<?php

declare(strict_types=1);

namespace App\Actions\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;

class GetGeographyDeletionBlockReasonAction
{
    public function handle(Country|State|District|Subdistrict $record): ?string
    {
        return match (true) {
            $record instanceof Country => $this->countryReason($record),
            $record instanceof State => $this->stateReason($record),
            $record instanceof District => $this->districtReason($record),
            $record instanceof Subdistrict => $this->subdistrictReason($record),
        };
    }

    private function countryReason(Country $country): ?string
    {
        if ((int) $country->getKey() === 132) {
            return 'Malaysia (ID 132) is the application default country and cannot be deleted.';
        }

        if ($country->states()->exists()) {
            return 'Delete or reassign this country\'s states before deleting it.';
        }

        if ($country->cities()->exists()) {
            return 'Delete or reassign this country\'s cities before deleting it.';
        }

        if ($country->addresses()->exists()) {
            return 'This country is still referenced by one or more addresses.';
        }

        return null;
    }

    private function stateReason(State $state): ?string
    {
        if ($state->districts()->exists()) {
            return 'Delete or reassign this state\'s districts before deleting it.';
        }

        if ($state->cities()->exists()) {
            return 'Delete or reassign this state\'s cities before deleting it.';
        }

        if ($state->addresses()->exists()) {
            return 'This state is still referenced by one or more addresses.';
        }

        return null;
    }

    private function districtReason(District $district): ?string
    {
        if ($district->subdistricts()->exists()) {
            return 'Delete or reassign this district\'s subdistricts before deleting it.';
        }

        if ($district->addresses()->exists()) {
            return 'This district is still referenced by one or more addresses.';
        }

        return null;
    }

    private function subdistrictReason(Subdistrict $subdistrict): ?string
    {
        if ($subdistrict->addresses()->exists()) {
            return 'This subdistrict is still referenced by one or more addresses.';
        }

        return null;
    }
}
