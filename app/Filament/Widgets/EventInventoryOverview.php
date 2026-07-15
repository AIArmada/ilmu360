<?php

namespace App\Filament\Widgets;

use AIArmada\Events\Models\EventAttribute;
use App\Models\Event;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;

class EventInventoryOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected ?string $heading = 'Event Overview';

    protected ?string $description = 'Operational counts for active public-facing events.';

    #[\Override]
    protected function getStats(): array
    {
        $upcomingEvents = Event::query()
            ->active()
            ->where('starts_at', '>=', now())
            ->count();

        $pastEvents = Event::query()
            ->active()
            ->where('starts_at', '<', now())
            ->count();

        $featuredEvents = Event::query()
            ->active()
            ->whereExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from((new EventAttribute)->getTable())
                    ->whereColumn('event_attributes.event_id', 'events.id')
                    ->where('attribute_key', 'is_featured')
                    ->where('attribute_value', '1');
            })
            ->count();

        return [
            Stat::make('Upcoming Events', $upcomingEvents)
                ->description('Active public events ahead')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
            Stat::make('Past Events', $pastEvents)
                ->description('Active public events completed')
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray'),
            Stat::make('Featured Events', $featuredEvents)
                ->description('Active public events highlighted')
                ->descriptionIcon('heroicon-m-star')
                ->color('success'),
        ];
    }
}
