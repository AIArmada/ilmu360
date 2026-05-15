<?php

namespace App\Filament\Resources\Spaces\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SpaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Space Details')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('slug')
                            ->label('Slug'),
                        TextEntry::make('capacity')
                            ->label('Capacity')
                            ->placeholder('-'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Updated At')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Linked Institutions')
                    ->schema([
                        RepeatableEntry::make('institutions')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')
                                    ->label('Institution'),
                            ])
                            ->contained(false)
                            ->placeholder('No institutions linked yet.'),
                    ]),

                Section::make('Statistics')
                    ->schema([
                        TextEntry::make('institutions_count')
                            ->label('Institutions')
                            ->state(fn ($record): int => $record->institutions()->count())
                            ->numeric(),
                        TextEntry::make('events_count')
                            ->label('Events')
                            ->state(fn ($record): int => $record->events()->count())
                            ->numeric(),
                    ])
                    ->columns(2),
            ]);
    }
}
