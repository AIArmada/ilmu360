<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Verify pending institutions in one batch (event approval workflow).
 */
final readonly class VerifyPendingInstitutionsAction
{
    /**
     * @param  list<string>  $institutionIds
     */
    public function handle(array $institutionIds): void
    {
        if ($institutionIds === []) {
            return;
        }

        DirectoryModel::query('institution')
            ->whereIn('id', array_values(array_unique($institutionIds)))
            ->where('status', InstitutionStatus::Pending->value)
            ->get()
            ->each(function (Model $institution): void {
                $now = now();
                $institution->forceFill([
                    'status' => InstitutionStatus::Verified,
                    'verified_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();
            });
    }
}
