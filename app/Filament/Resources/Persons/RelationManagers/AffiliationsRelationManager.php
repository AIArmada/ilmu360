<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use AIArmada\FilamentPersons\Resources\PersonResource\RelationManagers\AffiliationsRelationManager as PackageAffiliationsRelationManager;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Institution;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables;
use Filament\Tables\Table;

class AffiliationsRelationManager extends PackageAffiliationsRelationManager
{
    /**
     * @return array<string, string>
     */
    #[\Override]
    public static function getInstitutionOptions(): array
    {
        return Institution::orderBy('name')->pluck('name', 'id')->toArray();
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('affiliation_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('institution_id')
                    ->label('Institution')
                    ->formatStateUsing(fn (?string $state): string => static::getInstitutionLabel($state ?? '') ?? $state ?? '-')
                    ->url(fn ($record): ?string => $record?->institution_id
                        ? InstitutionResource::getUrl('edit', ['record' => $record->institution_id])
                        : null),
                Tables\Columns\TextColumn::make('position')
                    ->placeholder('-'),
                Tables\Columns\IconColumn::make('is_primary')
                    ->boolean(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->date()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('left_at')
                    ->date()
                    ->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->form([
                        Select::make('affiliation_type')
                            ->options([
                                'member' => 'Member',
                                'employee' => 'Employee',
                                'advisor' => 'Advisor',
                                'partner' => 'Partner',
                            ])
                            ->required(),
                        Select::make('institution_id')
                            ->label('Institution')
                            ->options(static::getInstitutionOptions())
                            ->searchable()
                            ->preload(),
                        TextInput::make('position')
                            ->maxLength(50),
                        DatePicker::make('joined_at'),
                        DatePicker::make('left_at'),
                        Checkbox::make('is_primary'),
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
