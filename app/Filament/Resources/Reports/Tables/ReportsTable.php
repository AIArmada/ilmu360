<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\ResolveReportEntityMetadataAction;
use App\Filament\Resources\Reports\Support\ReportPresenter;
use App\Models\Report;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['entity', 'reporter', 'handler']))
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
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reporter.email')
                    ->label('Reporter')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('handler.email')
                    ->label('Handled by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(app(ResolveReportEntityMetadataAction::class)->options()),
                SelectFilter::make('category')
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
