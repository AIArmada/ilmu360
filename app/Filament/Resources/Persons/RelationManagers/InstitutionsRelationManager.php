<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Person;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InstitutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'institutions';

    protected static ?string $inverseRelationship = 'persons';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record): ?string => $record?->id
                        ? InstitutionResource::getUrl('edit', ['record' => $record->id])
                        : null),
                TextColumn::make('position')
                    ->label('Position')
                    ->searchable(),
                IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean(),
                TextColumn::make('joined_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('position')
                            ->placeholder('e.g. Imam, Guest Speaker'),
                        Toggle::make('is_primary')
                            ->label('Primary Affiliation'),
                        DatePicker::make('joined_at')
                            ->label('Joined At'),
                    ])
                    ->mutateDataUsing(static function (array $data): array {
                        $data['id'] ??= (string) Str::uuid();

                        return $data;
                    })
                    ->after(function (AttachAction $action): void {
                        $this->clearOtherPrimaryAffiliations($action->getRecord(), $action->getData());
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        TextInput::make('position'),
                        Toggle::make('is_primary'),
                        DatePicker::make('joined_at'),
                    ])
                    ->after(function (EditAction $action): void {
                        $this->clearOtherPrimaryAffiliations($action->getRecord(), $action->getData());
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Filament updates belongs-to-many pivot rows directly, so the affiliation
     * model observer cannot enforce this invariant for these actions.
     *
     * @param  Model|array<string, mixed>|null  $record
     * @param  array<string, mixed>  $data
     */
    private function clearOtherPrimaryAffiliations(Model|array|null $record, array $data): void
    {
        if (! (bool) ($data['is_primary'] ?? false) || ! $record instanceof Model) {
            return;
        }

        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Person) {
            return;
        }

        $owner->institutions()
            ->newPivotStatement()
            ->where('affiliatable_id', $owner->getKey())
            ->where('affiliatable_type', $owner->getMorphClass())
            ->where('institution_id', '!=', $record->getKey())
            ->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);
    }
}
