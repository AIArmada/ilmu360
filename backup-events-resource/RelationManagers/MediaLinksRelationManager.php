<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'mediaLinks';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options([
                        'livestream' => 'Livestream',
                        'recording' => 'Recording',
                        'playlist' => 'Playlist',
                        'slides' => 'Slides',
                        'other' => 'Other',
                    ])
                    ->required(),
                TextInput::make('provider')
                    ->maxLength(100),
                TextInput::make('url')
                    ->url()
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_primary')
                    ->label('Primary'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('provider')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('url')
                    ->searchable()
                    ->limit(40),
                IconColumn::make('is_primary')
                    ->boolean()
                    ->label('Primary'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
