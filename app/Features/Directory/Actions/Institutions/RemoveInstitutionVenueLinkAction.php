<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remove one institution↔venue link without touching either side's row.
 */
final readonly class RemoveInstitutionVenueLinkAction
{
    public function handle(string $institutionId, string $venueId): void
    {
        DB::transaction(function () use ($institutionId, $venueId): void {
            DirectoryModel::query('institution')
                ->whereKey($institutionId)
                ->lockForUpdate()
                ->first();

            DB::table('institution_venue')
                ->where('institution_id', $institutionId)
                ->where('venue_id', $venueId)
                ->delete();
        });
    }
}
