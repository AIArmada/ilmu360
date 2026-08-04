<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use AIArmada\Ticketing\Enums\TicketTypeVisibility;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TicketTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'ticketTypes';

    protected static ?string $title = 'Ticket Types';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::formComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->badge(),
                TextColumn::make('access_type')
                    ->badge(),
                TextColumn::make('price')
                    ->numeric(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('visibility')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function formComponents(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->required()
                ->maxLength(50),
            Textarea::make('description')
                ->maxLength(65535)
                ->columnSpanFull(),
            Select::make('access_type')
                ->options([
                    'general_admission' => 'General Admission',
                    'reserved_seating' => 'Reserved Seating',
                    'vip' => 'VIP',
                    'complimentary' => 'Complimentary',
                ])
                ->required(),
            Select::make('seating_mode')
                ->options([
                    'none' => 'None',
                    'general_admission' => 'General Admission',
                    'assigned' => 'Assigned',
                    'hybrid' => 'Hybrid',
                ])
                ->nullable(),
            TextInput::make('price')
                ->numeric()
                ->nullable(),
            TextInput::make('currency')
                ->maxLength(3)
                ->default('MYR'),
            TextInput::make('admits_quantity')
                ->numeric()
                ->integer()
                ->required()
                ->default(1),
            TextInput::make('min_quantity')
                ->numeric()
                ->integer()
                ->nullable(),
            TextInput::make('max_quantity')
                ->numeric()
                ->integer()
                ->nullable(),
            Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'paused' => 'Paused',
                    'sold_out' => 'Sold Out',
                    'ended' => 'Ended',
                    'cancelled' => 'Cancelled',
                ])
                ->required(),
            Select::make('visibility')
                ->options(TicketTypeVisibility::options())
                ->required(),
            DateTimePicker::make('sales_starts_at')
                ->nullable(),
            DateTimePicker::make('sales_ends_at')
                ->nullable(),
            TextInput::make('sort_order')
                ->numeric()
                ->integer()
                ->default(0),
        ];
    }
}
