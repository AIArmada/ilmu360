<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages;

use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListAppFeedback extends ListRecords
{
    #[\Override]
    protected static string $resource = AppFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    #[\Override]
    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        /** @var Collection<string, int|string> $counts */
        $counts = AppFeedback::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = static fn (AppFeedbackStatus $status): string => (string) ($counts[$status->value] ?? 0);

        $openCount = (int) ($counts[AppFeedbackStatus::New->value] ?? 0)
            + (int) ($counts[AppFeedbackStatus::Triaged->value] ?? 0);

        $openStatuses = [AppFeedbackStatus::New->value, AppFeedbackStatus::Triaged->value];

        return [
            'open' => Tab::make('open')
                ->label(__('Open'))
                ->badge((string) $openCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $openStatuses)),
            'new' => Tab::make('new')
                ->label(AppFeedbackStatus::New->getLabel())
                ->badge($countFor(AppFeedbackStatus::New))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AppFeedbackStatus::New->value)),
            'triaged' => Tab::make('triaged')
                ->label(AppFeedbackStatus::Triaged->getLabel())
                ->badge($countFor(AppFeedbackStatus::Triaged))
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AppFeedbackStatus::Triaged->value)),
            'resolved' => Tab::make('resolved')
                ->label(AppFeedbackStatus::Resolved->getLabel())
                ->badge($countFor(AppFeedbackStatus::Resolved))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AppFeedbackStatus::Resolved->value)),
            'declined' => Tab::make('declined')
                ->label(AppFeedbackStatus::Declined->getLabel())
                ->badge($countFor(AppFeedbackStatus::Declined))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', AppFeedbackStatus::Declined->value)),
            'all' => Tab::make('all')
                ->label(__('All'))
                ->badgeColor('gray'),
        ];
    }
}
