<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\Models\Report;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListReports extends ListRecords
{
    #[\Override]
    protected static string $resource = ReportResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    #[\Override]
    public function getDefaultActiveTab(): string|int|null
    {
        return Report::STATUS_OPEN;
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        /** @var Collection<string, int|string> $counts */
        $counts = Report::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = static fn (string $status): string => (string) ($counts[$status] ?? 0);

        return [
            'open' => Tab::make('open')
                ->label(__('Open'))
                ->badge($countFor(Report::STATUS_OPEN))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Report::STATUS_OPEN)),
            'triaged' => Tab::make('triaged')
                ->label(__('Triaged'))
                ->badge($countFor(Report::STATUS_TRIAGED))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Report::STATUS_TRIAGED)),
            'resolved' => Tab::make('resolved')
                ->label(__('Resolved'))
                ->badge($countFor(Report::STATUS_RESOLVED))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Report::STATUS_RESOLVED)),
            'dismissed' => Tab::make('dismissed')
                ->label(__('Dismissed'))
                ->badge($countFor(Report::STATUS_DISMISSED))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Report::STATUS_DISMISSED)),
            'all' => Tab::make('all')
                ->label(__('All'))
                ->badgeColor('gray'),
        ];
    }
}
