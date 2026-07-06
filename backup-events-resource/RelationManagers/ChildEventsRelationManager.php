<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ChildEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'childEvents';

    protected static ?string $title = 'Child Events';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Event) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass) && $ownerRecord->isParentProgram();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge(),
                TextColumn::make('event_format')
                    ->label('Format')
                    ->badge(),
            ])
            ->defaultSort('starts_at')
            ->heading('Child Events')
            ->description(fn (): string => $this->childEventsSummary())
            ->emptyStateHeading('No child events yet')
            ->emptyStateDescription('Use the add child event action to submit the first child under this parent program.')
            ->headerActions([
                Action::make('add_child_event')
                    ->label('Add Child Event')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => route('submit-event.create', ['parent' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Event $record): string => AhliEventResource::getUrl('view', ['record' => $record], panel: 'ahli')),
                EditAction::make()
                    ->url(fn (Event $record): string => AhliEventResource::getUrl('edit', ['record' => $record], panel: 'ahli')),
                Action::make('view_public')
                    ->label('View Public')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Event $record): string => route('events.show', $record))
                    ->openUrlInNewTab(),
            ]);
    }

    protected function childEventsSummary(): string
    {
        $ownerRecord = $this->getOwnerRecord();

        if (! $ownerRecord instanceof Event) {
            return 'Manage the child events attached to this parent program.';
        }

        $childEvents = $ownerRecord->childEvents()->get();

        if ($childEvents->isEmpty()) {
            return 'Manage the child events attached to this parent program.';
        }

        $summaryParts = [
            __('Total: :count', ['count' => $childEvents->count()]),
            __('Approved: :count', ['count' => $childEvents->where('status', 'approved')->count()]),
            __('Pending: :count', ['count' => $childEvents->where('status', 'pending')->count()]),
            __('Draft: :count', ['count' => $childEvents->where('status', 'draft')->count()]),
        ];

        $nextUpcomingChild = $childEvents
            ->filter(fn (Event $childEvent): bool => $childEvent->starts_at instanceof Carbon && $childEvent->starts_at->isFuture())
            ->sortBy('starts_at')
            ->first();

        if ($nextUpcomingChild instanceof Event && $nextUpcomingChild->starts_at instanceof Carbon) {
            $summaryParts[] = __('Next: :title on :date', [
                'title' => $nextUpcomingChild->title,
                'date' => $nextUpcomingChild->starts_at->format('d M Y'),
            ]);
        }

        return implode(' · ', $summaryParts);
    }
}
