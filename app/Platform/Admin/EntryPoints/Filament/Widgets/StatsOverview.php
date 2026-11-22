<?php

namespace App\Platform\Admin\EntryPoints\Filament\Widgets;

use AIArmada\CommerceSupport\Support\VersionedCache;
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
        /** @var list<array{label: string, count: int, description: string, url: ?string, sort: int}> $rows */
        $rows = app(VersionedCache::class)->remember(
            'admin:stats_overview:version:v1',
            'admin:stats_overview:v1',
            30,
            static function (): array {
                $collected = [];

                foreach (app()->tagged('admin.stats') as $provider) {
                    if (! $provider instanceof AdminStatProvider) {
                        continue;
                    }

                    foreach ($provider->stats() as $stat) {
                        $collected[] = [
                            'label' => $stat->label,
                            'count' => $stat->count,
                            'description' => $stat->description,
                            'url' => $stat->url,
                            'sort' => $stat->sort,
                        ];
                    }
                }

                usort($collected, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

                return $collected;
            });

        $stats = [];

        foreach ($rows as $row) {
            $count = $row['count'];

            $stats[] = Stat::make($row['label'], $count)
                ->description($row['description'])
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $count > 0 ? 'warning' : 'success')
                ->url($row['url']);
        }

        return $stats;
    }
}
