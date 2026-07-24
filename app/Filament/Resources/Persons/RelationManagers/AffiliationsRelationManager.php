<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Enums\AffiliationType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AffiliationsRelationManager extends RelationManager
{
    protected static string $relationship = 'affiliations';

    protected static ?string $title = 'Affiliations';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('institution_id')
                    ->label(__('Institution'))
                    ->relationship('institution', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('affiliation_type')
                    ->label(__('Affiliation Type'))
                    ->options(AffiliationType::class)
                    ->required(),
                Toggle::make('is_primary')
                    ->label(__('Is Primary')),
                DatePicker::make('joined_at')
                    ->label(__('Joined At')),
                DatePicker::make('left_at')
                    ->label(__('Left At')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('institution.name')
                    ->label(__('Institution'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('affiliation_type')
                    ->label(__('Affiliation Type'))
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_primary')
                    ->label(__('Is Primary'))
                    ->boolean(),
                TextColumn::make('joined_at')
                    ->label(__('Joined At'))
                    ->date()
                    ->sortable(),
                TextColumn::make('left_at')
                    ->label(__('Left At'))
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
