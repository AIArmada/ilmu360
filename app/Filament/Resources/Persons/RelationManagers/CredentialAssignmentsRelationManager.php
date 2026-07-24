<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CredentialAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'credentialAssignments';

    protected static ?string $title = 'Credentials';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('credential_id')
                    ->label(__('Credential'))
                    ->relationship('credential', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('date_obtained')
                    ->label(__('Date Obtained')),
                TextInput::make('registration_number')
                    ->label(__('Registration Number'))
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('credential.name')
                    ->label(__('Credential'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('date_obtained')
                    ->label(__('Date Obtained'))
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
