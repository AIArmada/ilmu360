<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionModel;
use Throwable;

/**
 * Delete contribution requests one row at a time.
 *
 * Row-by-row deletes keep model events, auditing and the deleted-model copy
 * for every request; a mass query delete would bypass them. Failures are
 * isolated per row so the Filament bulk action can report partial success,
 * matching the vendor delete behaviour.
 */
final readonly class DeleteContributionRequestsAction
{
    /**
     * @param  list<string>  $requestIds
     */
    public function handle(array $requestIds): int
    {
        $deleted = 0;
        $reported = false;

        foreach (ContributionModel::query('contribution_request')->whereKey($requestIds)->get() as $request) {
            try {
                if ($request->delete()) {
                    $deleted++;

                    continue;
                }
            } catch (Throwable $exception) {
                if (! $reported) {
                    report($exception);
                    $reported = true;
                }

                continue;
            }
        }

        return $deleted;
    }
}
