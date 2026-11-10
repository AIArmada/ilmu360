<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use AIArmada\FilamentPersons\Resources\PersonResource\RelationManagers\AffiliationsRelationManager as PackageAffiliationsRelationManager;
use App\Features\Directory\Api\PersonWorkflow;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Institution;
use App\Models\Person;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                            ->preload()
                            ->required(),
                        TextInput::make('position')
                            ->maxLength(50),
                        DatePicker::make('joined_at'),
                        DatePicker::make('left_at'),
                        Checkbox::make('is_primary'),
                    ])
                    ->action(function (CreateAction $action, array $data): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->attachAffiliation(
                            (string) $owner->getKey(),
                            (string) $data['institution_id'],
                            $data,
                        );

                        $action->success();
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->action(function (EditAction $action, Model $record, array $data): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->updateAffiliation(
                            (string) $owner->getKey(),
                            (string) $record->getKey(),
                            $data,
                        );

                        $action->success();
                    }),
                DeleteAction::make()
                    ->action(function (DeleteAction $action, Model $record): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->deleteAffiliations(
                            (string) $owner->getKey(),
                            [(string) $record->getKey()],
                        );

                        $action->success();
                    }),
            ]);
    }
}
