<?php

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Tables;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestModerationActions;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class ContributionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForType($state))
                    ->color(fn (mixed $state): string => match ($state instanceof ContributionRequestType ? $state->value : (string) $state) {
                        ContributionRequestType::Create->value => 'info',
                        ContributionRequestType::Update->value => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForSubject($state))
                    ->sortable(),
                TextColumn::make('entity_summary')
                    ->label('Record')
                    ->state(fn (ContributionRequest $record): string => ContributionRequestPresenter::entityTitle($record))
                    ->url(fn (ContributionRequest $record): string => ContributionRequestResource::getUrl('view', ['record' => $record]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->orWhere('proposed_data', 'like', '%'.$search.'%')
                        ->orWhere('proposer_note', 'like', '%'.$search.'%')
                        ->orWhereHasMorph('entity', [Institution::class], fn (Builder $entityQuery): Builder => $entityQuery->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHasMorph('entity', [Person::class], fn (Builder $entityQuery): Builder => $entityQuery->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHasMorph('entity', [Event::class], fn (Builder $entityQuery): Builder => $entityQuery->where('title', 'like', '%'.$search.'%'))
                        ->orWhereHasMorph('entity', [Reference::class], fn (Builder $entityQuery): Builder => $entityQuery->where('title', 'like', '%'.$search.'%'))),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => ContributionRequestPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('proposer.name')
                    ->label('Proposer')
                    ->description(fn (ContributionRequest $record): ?string => $record->proposer?->email)
                    ->searchable(['name', 'email'])
                    ->placeholder('-'),
                TextColumn::make('changes_summary')
                    ->label('Summary')
                    ->state(fn (ContributionRequest $record): string => app(ContributionsHubPresenter::class)->requestSummaryLine($record) ?? '-')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (ContributionRequest $record): ?string => app(ContributionsHubPresenter::class)->requestSummaryLine($record)),
                TextColumn::make('proposer_note')
                    ->label('Proposer Note')
                    ->limit(60)
                    ->tooltip(fn (ContributionRequest $record): ?string => $record->proposer_note)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewer.email')
                    ->label('Reviewer')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->since()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ContributionRequestPresenter::statusOptions()),
                SelectFilter::make('type')
                    ->options(ContributionRequestPresenter::typeOptions()),
                SelectFilter::make('subject_type')
                    ->options(ContributionRequestPresenter::subjectOptions()),
            ])
            ->recordActions([
                ContributionRequestModerationActions::approveAction(),
                ContributionRequestModerationActions::rejectAction(),
                Action::make('view_entity')
                    ->label('Open Record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ContributionRequest $record): ?string => ContributionRequestPresenter::entityAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (ContributionRequest $record): bool => filled(ContributionRequestPresenter::entityAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (ContributionRequest $record): string => ContributionRequestResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            try {
                                $deleted = app(ContributionRequestLifecycle::class)->delete(
                                    $records->modelKeys(),
                                );
                            } catch (Throwable $exception) {
                                report($exception);

                                $action->reportCompleteBulkProcessingFailure();

                                return;
                            }

                            $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                        }),
                ]),
            ]);
    }
}
