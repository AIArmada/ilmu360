<?php

declare(strict_types=1);

namespace App\Filament\Resources\Spaces\Tables;

use App\Filament\Resources\Spaces\SpaceResource;
use App\Features\Directory\Api\SpaceWorkflow;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SpacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->sortable(),
                TextColumn::make('institutions_count')
                    ->label('Institutions')
                    ->counts('institutions')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn ($record): string => SpaceResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            app(SpaceWorkflow::class)->delete(
                                $records
                                    ->map(static fn (Model $space): string => (string) $space->getKey())
                                    ->values()
                                    ->all(),
                            );

                            $action->success();
                        }),
                ]),
            ]);
    }
}
