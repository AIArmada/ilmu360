<?php

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Tables;

use App\Features\Reports\Actions\DeleteReportsAction;
use App\Features\Reports\Actions\ResolveReportCategoryOptionsAction;
use App\Features\Reports\Actions\ResolveReportEntityMetadataAction;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Support\ReportModerationActions;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Support\ReportPresenter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                SpatieMediaLibraryImageColumn::make('evidence')
                    ->label('Evidence')
                    ->collection('evidence')
                    ->conversion('thumb')
                    ->square()
                    ->size(52),
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('entity_subject')
                    ->label('Subject')
                    ->state(fn (Report $record): string => ReportPresenter::entityTitle($record))
                    ->url(fn (Report $record): ?string => ReportPresenter::entityAdminUrl($record))
                    ->openUrlInNewTab(),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(function (?string $state, Report $record): string {
                        if (! is_string($state) || $state === '') {
                            return '-';
                        }

                        return app(ResolveReportCategoryOptionsAction::class)->handle((string) $record->entity_type)[$state]
                            ?? Str::headline($state);
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('report_type', $direction)),
                TextColumn::make('description')
                    ->label('Details')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (Report $record): ?string => $record->description)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('message', 'like', '%'.$search.'%'))
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::headline($state) : '-')
                    ->color(fn (?string $state): string => match ($state) {
                        Report::STATUS_OPEN => 'danger',
                        Report::STATUS_TRIAGED => 'warning',
                        Report::STATUS_RESOLVED => 'success',
                        Report::STATUS_DISMISSED => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('reporter.email')
                    ->label('Reporter')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('handler.email')
                    ->label('Handled by')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(app(ResolveReportEntityMetadataAction::class)->options()),
                SelectFilter::make('category')
                    ->attribute('report_type')
                    ->options(app(ResolveReportCategoryOptionsAction::class)->handle()),
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'triaged' => 'Triaged',
                        'resolved' => 'Resolved',
                        'dismissed' => 'Dismissed',
                    ]),
            ])
            ->recordActions([
                ReportModerationActions::triageAction(),
                ReportModerationActions::resolveAction(),
                ReportModerationActions::dismissAction(),
                ReportModerationActions::reopenAction(),
                ReportModerationActions::openEntityAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (Collection $records): void {
                            app(DeleteReportsAction::class)->handle($records->pluck('id')->all());

                            ReportResource::flushNavigationBadgeCache();

                            Notification::make()
                                ->success()
                                ->title(__('Reports deleted'))
                                ->send();
                        }),
                ]),
            ]);
    }
}
