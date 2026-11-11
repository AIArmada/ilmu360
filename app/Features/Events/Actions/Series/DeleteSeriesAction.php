<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Series;

use App\Features\Events\Support\EventModel;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Delete an event series and its owned item/rule rows.
 *
 * Language pivots and media stay with normal model deletion semantics; this
 * action only removes the series-specific child records the package does not
 * cascade through application logic.
 */
final readonly class DeleteSeriesAction
{
    use AsAction;

    public function handle(string $seriesId): bool
    {
        return DB::transaction(function () use ($seriesId): bool {
            $seriesClass = EventModel::series();
            $series = $seriesClass::query()->find($seriesId);

            if ($series === null) {
                return false;
            }

            DB::table(config('events.database.tables.event_series_items', 'event_series_items'))
                ->where('event_series_id', $seriesId)
                ->delete();

            DB::table(config('events.database.tables.event_series_rules', 'event_series_rules'))
                ->where('event_series_id', $seriesId)
                ->delete();

            $series->delete();

            return true;
        });
    }
}
