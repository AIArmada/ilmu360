<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Enums\PersonNameType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NamesRelationManager extends RelationManager
{
    protected static string $relationship = 'names';

    protected static ?string $title = 'Names';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('name_type')
                    ->label(__('Name Type'))
                    ->options(PersonNameType::class)
                    ->required(),
                TextInput::make('full_name')
                    ->label(__('Full Name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('language_code')
                    ->label(__('Language Code'))
                    ->maxLength(10)
                    ->placeholder(__('e.g. ms, ar, en')),
                Toggle::make('is_primary')
                    ->label(__('Is Primary')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('name_type')
                    ->label(__('Name Type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label(__('Full Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language_code')
                    ->label(__('Language Code'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_primary')
                    ->label(__('Is Primary'))
                    ->boolean(),
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
