<?php

declare(strict_types=1);

namespace App\Filament\Resources\Institutions\Tables;

use App\Features\Directory\Api\InstitutionWorkflow;
use App\Shared\Directory\Enums\InstitutionStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class InstitutionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('logo')
                    ->label('Logo')
                    ->collection('logo')
                    ->conversion('thumb')
                    ->circular()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('addresses.state')
                    ->label('State')
                    ->placeholder('-'),
                TextColumn::make('address.city')
                    ->label('City')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'masjid' => 'Masjid',
                        'surau' => 'Surau',
                        'others' => 'Others',
                    ]),
                SelectFilter::make('status')
                    ->options(InstitutionStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            try {
                                $deleted = app(InstitutionWorkflow::class)->delete(
                                    $records->modelKeys(),
                                );
                            } catch (Throwable $exception) {
                                report($exception);

                                $action->reportCompleteBulkProcessingFailure();

                                return;
                            }

                            if ($deleted > 0) {
                                $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                            }

                            if ($deleted < $records->count()) {
                                $action->reportBulkProcessingFailure();
                            }
                        }),
                ]),
            ]);
    }
}
