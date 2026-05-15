<?php

namespace App\Filament\Resources\DonationChannels\Tables;

use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Institution;
use App\Models\Speaker;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DonationChannelsTable
{
    public static function configure(Table $table, bool $showOwnerColumn = true): Table
    {
        $columns = [
            SpatieMediaLibraryImageColumn::make('qr')
                ->label('QR')
                ->collection('qr')
                ->conversion('thumb')
                ->square()
                ->size(52),
        ];

        if ($showOwnerColumn) {
            $columns[] = TextColumn::make('donatable.name')
                ->label('Owner')
                ->description(fn ($record) => class_basename($record->donatable_type))
                ->searchable()
                ->sortable()
                ->url(function ($record): ?string {
                    if (! $record->donatable) {
                        return null;
                    }

                    return match ($record->donatable::class) {
                        Institution::class => InstitutionResource::getUrl('edit', ['record' => $record->donatable->id]),
                        Speaker::class => SpeakerResource::getUrl('edit', ['record' => $record->donatable->id]),
                        default => null,
                    };
                });
        }

        return $table
            ->columns([
                ...$columns,
                TextColumn::make('recipient')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('method_display')
                    ->label('Method')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'Bank Account' => 'info',
                        'DuitNow' => 'success',
                        'E-Wallet' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('payment_details')
                    ->label('Details')
                    ->searchable(['bank_name', 'account_number', 'duitnow_value', 'ewallet_handle']),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'verified' => 'success',
                        'unverified' => 'warning',
                        'rejected' => 'danger',
                        'inactive' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
