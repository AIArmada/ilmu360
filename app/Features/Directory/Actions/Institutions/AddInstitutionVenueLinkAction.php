<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionVenueRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Add or update one institution↔venue link.
 *
 * The owning institution and target venue rows are locked before the demote
 * and upsert, so concurrent primary swaps serialize and a failed insert rolls
 * the demote back inside the same transaction.
 */
final readonly class AddInstitutionVenueLinkAction
{
    public function __construct(private DemoteInstitutionVenuePrimaryAction $demotePrimary) {}

    public function handle(
        string $institutionId,
        string $venueId,
        InstitutionVenueRole|string $role,
        bool $isPrimary = false,
    ): void {
        $role = $this->normalizeRole($role);

        if (! Str::isUuid($institutionId)) {
            throw ValidationException::withMessages([
                'institution_id' => __('The selected institution is invalid.'),
            ]);
        }

        if (! Str::isUuid($venueId)) {
            throw ValidationException::withMessages([
                'venue_id' => __('The selected venue is invalid.'),
            ]);
        }

        DB::transaction(function () use ($institutionId, $venueId, $role, $isPrimary): void {
            $institution = DirectoryModel::query('institution')
                ->whereKey($institutionId)
                ->lockForUpdate()
                ->first();

            if (! $institution instanceof Model) {
                throw ValidationException::withMessages([
                    'institution_id' => __('The selected institution is invalid.'),
                ]);
            }

            $venue = DirectoryModel::query('venue')
                ->whereKey($venueId)
                ->lockForUpdate()
                ->first();

            if (! $venue instanceof Model) {
                throw ValidationException::withMessages([
                    'venue_id' => __('The selected venue is invalid.'),
                ]);
            }

            if ($isPrimary) {
                $this->demotePrimary->handle($institutionId, $role->value, $venueId);
            }

            $now = now();
            $table = DB::table('institution_venue');
            $exists = $table
                ->where('institution_id', $institutionId)
                ->where('venue_id', $venueId)
                ->exists();

            if ($exists) {
                $table
                    ->where('institution_id', $institutionId)
                    ->where('venue_id', $venueId)
                    ->update([
                        'role' => $role->value,
                        'is_primary' => $isPrimary,
                        'updated_at' => $now,
                    ]);

                return;
            }

            $table->insert([
                'id' => (string) Str::uuid(),
                'institution_id' => $institutionId,
                'venue_id' => $venueId,
                'role' => $role->value,
                'is_primary' => $isPrimary,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function normalizeRole(InstitutionVenueRole|string $role): InstitutionVenueRole
    {
        if ($role instanceof InstitutionVenueRole) {
            return $role;
        }

        return InstitutionVenueRole::tryFrom($role) ?? throw ValidationException::withMessages([
            'role' => __('The selected venue role is invalid.'),
        ]);
    }
}
