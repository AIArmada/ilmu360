<?php

namespace App\Filament\Widgets;

use App\Models\Event;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
            ->where('is_featured', true)
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
