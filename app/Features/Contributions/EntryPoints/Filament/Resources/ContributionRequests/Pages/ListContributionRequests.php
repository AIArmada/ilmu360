<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Models\ContributionRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListContributionRequests extends ListRecords
{
    #[\Override]
    protected static string $resource = ContributionRequestResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }

    #[\Override]
    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        /** @var Collection<string, int|string> $counts */
        $counts = ContributionRequest::query()
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = static fn (ContributionRequestStatus $status): string => (string) ($counts[$status->value] ?? 0);

        return [
            'pending' => Tab::make('pending')
                ->label(__('Pending'))
                ->badge($countFor(ContributionRequestStatus::Pending))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContributionRequestStatus::Pending->value)),
            'approved' => Tab::make('approved')
                ->label(__('Approved'))
                ->badge($countFor(ContributionRequestStatus::Approved))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContributionRequestStatus::Approved->value)),
            'rejected' => Tab::make('rejected')
                ->label(__('Rejected'))
                ->badge($countFor(ContributionRequestStatus::Rejected))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContributionRequestStatus::Rejected->value)),
            'cancelled' => Tab::make('cancelled')
                ->label(__('Cancelled'))
                ->badge($countFor(ContributionRequestStatus::Cancelled))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContributionRequestStatus::Cancelled->value)),
            'all' => Tab::make('all')
                ->label(__('All')),
        ];
    }
}
