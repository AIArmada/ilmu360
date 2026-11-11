<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Shared\Directory\Enums\InstitutionVenueRole;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Demote the current primary venue link for one institution role.
 *
 * Callers must already hold the owning institution row lock and transaction;
 * this action deliberately opens neither so it can run inside a pivot save or
 * a larger bridge sync without widening the lock scope.
 */
final readonly class DemoteInstitutionVenuePrimaryAction
{
    public function handle(
        string $institutionId,
        InstitutionVenueRole|string $role,
        ?string $exceptVenueId = null,
    ): void {
        $roleValue = $role instanceof InstitutionVenueRole
            ? $role->value
            : InstitutionVenueRole::tryFrom($role)?->value;

        if ($roleValue === null) {
            throw new InvalidArgumentException('The institution venue role is invalid.');
        }

        DB::table('institution_venue')
            ->where('institution_id', $institutionId)
            ->where('role', $roleValue)
            ->where('is_primary', true)
            ->when(
                $exceptVenueId !== null,
                static fn ($query) => $query->where('venue_id', '!=', $exceptVenueId),
            )
            ->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);
    }
}
