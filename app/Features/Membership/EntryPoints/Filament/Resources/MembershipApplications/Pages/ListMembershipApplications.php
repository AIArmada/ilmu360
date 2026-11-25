<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\Models\MembershipApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListMembershipApplications extends ListRecords
{
    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected static string $resource = MembershipApplicationResource::class;

    #[\Override]
    public function getDefaultActiveTab(): string|int|null
    {
        return ApplicationStatus::Pending->value;
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        /** @var Collection<string, int|string> $counts */
        $counts = MembershipApplication::query()
            ->withoutOwnerScope()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = static fn (ApplicationStatus $status): string => (string) ($counts[$status->value] ?? 0);

        return [
            'pending' => Tab::make('pending')
                ->label(__('Pending'))
                ->badge($countFor(ApplicationStatus::Pending))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApplicationStatus::Pending->value)),
            'approved' => Tab::make('approved')
                ->label(__('Approved'))
                ->badge($countFor(ApplicationStatus::Approved))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApplicationStatus::Approved->value)),
            'rejected' => Tab::make('rejected')
                ->label(__('Rejected'))
                ->badge($countFor(ApplicationStatus::Rejected))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApplicationStatus::Rejected->value)),
            'cancelled' => Tab::make('cancelled')
                ->label(__('Cancelled'))
                ->badge($countFor(ApplicationStatus::Cancelled))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApplicationStatus::Cancelled->value)),
            'all' => Tab::make('all')
                ->label(__('All'))
                ->badgeColor('gray'),
        ];
    }
}
