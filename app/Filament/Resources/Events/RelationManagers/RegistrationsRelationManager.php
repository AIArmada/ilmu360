<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Event) {
            return false;
        }

        $ownerRecord->loadMissing('settings');

        return parent::canViewForRecord($ownerRecord, $pageClass)
            && (bool) $ownerRecord->settings?->registration_required;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Name')
                    ->formatStateUsing(fn (string $state, Model $record): string => method_exists($record, 'resolvedName')
                        ? ($record->resolvedName() ?? '-')
                        : '-'),
                TextColumn::make('id')
                    ->label('Email')
                    ->formatStateUsing(fn (string $state, Model $record): string => method_exists($record, 'resolvedEmail')
                        ? ($record->resolvedEmail() ?? '-')
                        : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('Phone')
                    ->formatStateUsing(fn (string $state, Model $record): string => method_exists($record, 'resolvedPhone')
                        ? ($record->resolvedPhone() ?? '-')
                        : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->formatStateUsing(static fn (mixed $state): string => is_object($state) && method_exists($state, 'label')
                        ? $state->label()
                        : Str::headline((string) $state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
