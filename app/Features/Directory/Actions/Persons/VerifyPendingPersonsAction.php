<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Verify pending people in one batch (event approval workflow).
 */
final readonly class VerifyPendingPersonsAction
{
    /**
     * @param  list<string>  $personIds
     */
    public function handle(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }

        DirectoryModel::query('person')
            ->whereIn('id', array_values(array_unique($personIds)))
            ->where('status', 'pending')
            ->get()
            ->each(function (Model $person): void {
                $now = now();
                $person->forceFill([
                    'status' => 'verified',
                    'verified_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();
            });
    }
}
