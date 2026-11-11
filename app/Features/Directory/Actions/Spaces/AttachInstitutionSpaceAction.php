<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attach or update one institution↔space link with an optional capacity
 * override.
 *
 * The space row is locked for the duration so concurrent syncs of the same
 * space serialize.
 */
final readonly class AttachInstitutionSpaceAction
{
    public function handle(string $institutionId, string $spaceId, ?int $capacity = null): void
    {
        if (! Str::isUuid($institutionId)) {
            throw ValidationException::withMessages([
                'institution_id' => __('The selected institution is invalid.'),
            ]);
        }

        if (! Str::isUuid($spaceId)) {
            throw ValidationException::withMessages([
                'space_id' => __('The selected space is invalid.'),
            ]);
        }

        if ($capacity !== null && $capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => __('The capacity must be at least 1.'),
            ]);
        }

        DB::transaction(function () use ($institutionId, $spaceId, $capacity): void {
            $space = DirectoryModel::query('space')
                ->whereKey($spaceId)
                ->lockForUpdate()
                ->first();

            if (! $space instanceof Model) {
                throw ValidationException::withMessages([
                    'space_id' => __('The selected space is invalid.'),
                ]);
            }

            if ($space->getAttribute('venue_id') !== null) {
                throw ValidationException::withMessages([
                    'institutions' => __('Venue-owned spaces cannot be linked to institutions.'),
                ]);
            }

            $institution = DirectoryModel::query('institution')
                ->whereKey($institutionId)
                ->lockForUpdate()
                ->first();

            if (! $institution instanceof Model) {
                throw ValidationException::withMessages([
                    'institution_id' => __('The selected institution is invalid.'),
                ]);
            }

            $now = now();
            $table = DB::table('institution_space');
            $exists = $table
                ->where('institution_id', $institutionId)
                ->where('space_id', $spaceId)
                ->exists();

            if ($exists) {
                $table
                    ->where('institution_id', $institutionId)
                    ->where('space_id', $spaceId)
                    ->update([
                        'capacity' => $capacity,
                        'updated_at' => $now,
                    ]);

                return;
            }

            $table->insert([
                'institution_id' => $institutionId,
                'space_id' => $spaceId,
                'capacity' => $capacity,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }
}
