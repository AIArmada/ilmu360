<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Filament\Resources\Persons\RelationManagers;

use AIArmada\FilamentPersons\Resources\PersonResource\RelationManagers\AffiliationsRelationManager as PackageAffiliationsRelationManager;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Platform\Admin\AdminResourceLink;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AffiliationsRelationManager extends PackageAffiliationsRelationManager
{
    /**
     * @return array<string, string>
     */
    #[\Override]
    public static function getInstitutionOptions(?string $search = null, int $limit = 50): array
    {
        return app(InstitutionLookup::class)->nameOptions($search ?? '', $limit);
    }

    #[\Override]
    public static function getInstitutionLabel(string $id): ?string
    {
        return $id !== ''
            ? (app(InstitutionLookup::class)->namesByIds([$id])[$id] ?? null)
            : null;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('institution'))
            ->columns([
                Tables\Columns\TextColumn::make('affiliation_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('institution_id')
                    ->label('Institution')
                    ->formatStateUsing(fn (?string $state): string => static::getInstitutionLabel($state ?? '') ?? $state ?? '-')
                    ->url(fn ($record): ?string => $record?->institution_id
                        ? AdminResourceLink::to('institution', 'edit', (string) $record->institution_id)
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
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::getInstitutionOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) && $value !== '' ? static::getInstitutionLabel($value) : null)
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
