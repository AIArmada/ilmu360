<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remove institution↔space bridge rows without touching either side's row.
 */
final readonly class DetachInstitutionSpaceAction
{
    public function handle(string $institutionId, string $spaceId): void
    {
        DB::transaction(function () use ($institutionId, $spaceId): void {
            DirectoryModel::query('space')
                ->whereKey($spaceId)
                ->lockForUpdate()
                ->first();

            DB::table('institution_space')
                ->where('institution_id', $institutionId)
                ->where('space_id', $spaceId)
                ->delete();
        });
    }

    /**
     * Remove every institution link for a space that is being deleted.
     */
    public function forSpace(string $spaceId): void
    {
        DB::table('institution_space')
            ->where('space_id', $spaceId)
            ->delete();
    }

    /**
     * Remove every space link for an institution that is being deleted.
     */
    public function forInstitution(string $institutionId): void
    {
        DB::table('institution_space')
            ->where('institution_id', $institutionId)
            ->delete();
    }
}
