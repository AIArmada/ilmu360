<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promote one institution↔venue link to primary for its role.
 *
 * The previous primary for the same role is demoted in the same transaction
 * under the owning institution's row lock.
 */
final readonly class SetInstitutionVenuePrimaryAction
{
    public function __construct(private DemoteInstitutionVenuePrimaryAction $demotePrimary) {}

    public function handle(string $institutionId, string $venueId): void
    {
        DB::transaction(function () use ($institutionId, $venueId): void {
            $institution = DirectoryModel::query('institution')
                ->whereKey($institutionId)
                ->lockForUpdate()
                ->first();

            if (! $institution instanceof Model) {
                throw ValidationException::withMessages([
                    'institution_id' => __('The selected institution is invalid.'),
                ]);
            }

            $link = DB::table('institution_venue')
                ->where('institution_id', $institutionId)
                ->where('venue_id', $venueId)
                ->first();

            if ($link === null) {
                throw ValidationException::withMessages([
                    'venue_id' => __('The selected venue is not linked to this institution.'),
                ]);
            }

            $role = is_string($link->role ?? null) ? $link->role : '';

            $this->demotePrimary->handle($institutionId, $role, $venueId);

            DB::table('institution_venue')
                ->where('institution_id', $institutionId)
                ->where('venue_id', $venueId)
                ->update([
                    'is_primary' => true,
                    'updated_at' => now(),
                ]);
        });
    }
}
