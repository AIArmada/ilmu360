<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Enums\AssignmentStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TitlesRelationManager extends RelationManager
{
    protected static string $relationship = 'titleAssignments';

    protected static ?string $title = 'Titles';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('title_id')
                    ->label(__('Title'))
                    ->relationship('title', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('status')
                    ->label(__('Status'))
                    ->options(AssignmentStatus::class)
                    ->required(),
                DatePicker::make('date_awarded')
                    ->label(__('Date Awarded')),
                DatePicker::make('date_expired')
                    ->label(__('Date Expired')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('title.name')
                    ->label(__('Title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('date_awarded')
                    ->label(__('Date Awarded'))
                    ->date()
                    ->sortable(),
                TextColumn::make('date_expired')
                    ->label(__('Date Expired'))
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([])
            ->bulkActions([]);
    }
}
