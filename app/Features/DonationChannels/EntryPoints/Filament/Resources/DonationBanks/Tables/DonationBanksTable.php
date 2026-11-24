<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Tables;

use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankAdminActions;
use App\Features\DonationChannels\Models\DonationBank;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DonationBanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->with('country')
                ->withExists('referencedChannels'))
            ->columns([
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (mixed $state): string => $state instanceof DonationBankStatus ? $state->getLabel() : (string) $state)
                    ->color(static fn (mixed $state): string => $state instanceof DonationBankStatus ? $state->color() : 'gray')
                    ->sortable(),
                TextColumn::make('source_url')
                    ->label('Source')
                    ->url(static fn (DonationBank $record): string => (string) $record->getAttribute('source_url'))
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('country_id')
                    ->label('Country')
                    ->relationship('country', 'name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(collect(DonationBankStatus::cases())
                        ->mapWithKeys(static fn (DonationBankStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DonationBankAdminActions::retire(),
                DonationBankAdminActions::reactivate(),
                DonationBankAdminActions::delete(),
            ])
            ->defaultSort('name');
    }
}
