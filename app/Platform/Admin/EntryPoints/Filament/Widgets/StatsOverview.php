<?php

namespace App\Platform\Admin\EntryPoints\Filament\Widgets;

use App\Platform\Admin\Filament\Stats\AdminStatProvider;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    #[\Override]
    protected static bool $isLazy = false;

    #[\Override]
    protected static ?int $sort = 1;

    #[\Override]
    protected ?string $pollingInterval = '60s';

    #[\Override]
    protected ?string $heading = 'Needs Approval';

    #[\Override]
    protected ?string $description = 'Start here. These counts lead directly to the pending review work.';

    #[\Override]
    protected function getStats(): array
    {
        $stats = [];

        foreach (app()->tagged('admin.stats') as $provider) {
            if (! $provider instanceof AdminStatProvider) {
                continue;
            }

            foreach ($provider->stats() as $stat) {
                $stats[] = Stat::make($stat->label, $stat->count)
                    ->description($stat->description)
                    ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                    ->color(fn (): string => $stat->count > 0 ? 'warning' : 'success')
                    ->url($stat->url);
            }
        }

        return $stats;
    }
}
