<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Space;
use Illuminate\Database\Eloquent\Builder;

interface SpaceEligibilityResolver
{
    /**
     * @return Builder<Space>
     */
    public function catalogQuery(): Builder;

    /**
     * @return Builder<Space>
     */
    public function institutionQuery(string $institutionId): Builder;

    /**
     * @return Builder<Space>
     */
    public function venueQuery(string $venueId): Builder;

    /**
     * @param  list<string>  $spaceIds
     */
    public function validateInstitutionSelection(string $institutionId, array $spaceIds): void;

    /**
     * @param  list<string>  $spaceIds
     */
    public function validateVenueSelection(string $venueId, array $spaceIds): void;
}
