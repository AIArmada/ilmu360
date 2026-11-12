<?php

namespace App\Filament\Widgets;

use App\Models\Event;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class EventInventoryOverview extends StatsOverviewWidget
{
    #[\Override]
    protected static bool $isLazy = false;

    #[\Override]
    protected static ?int $sort = 2;

    #[\Override]
    protected ?string $pollingInterval = '60s';

    #[\Override]
    protected ?string $heading = 'Event Overview';

    #[\Override]
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

        $featuredOccurrences = Event::query()
            ->active()
            ->withCount([
                'occurrences as featured_occurrences_count' => fn (Builder $query): Builder => $query->where('is_featured', true),
            ])
            ->get()
            ->sum(fn (Event $event): int => (int) $event->getAttribute('featured_occurrences_count'));

        return [
            Stat::make('Upcoming Events', $upcomingEvents)
                ->description('Active public events ahead')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
            Stat::make('Past Events', $pastEvents)
                ->description('Active public events completed')
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray'),
            Stat::make('Featured Occurrences', $featuredOccurrences)
                ->description('Active public occurrence dates highlighted')
                ->descriptionIcon('heroicon-m-star')
                ->color('success'),
        ];
    }
}
