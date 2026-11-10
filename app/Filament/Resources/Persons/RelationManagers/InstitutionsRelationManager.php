<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Features\Directory\Api\PersonWorkflow;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Person;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use App\Features\Directory\Api\InstitutionWorkflow;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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
                    ->action(function (AttachAction $action, array $data): void {
                        $owner = $this->getOwnerRecord();
                        $institutionId = $data['recordId'] ?? null;

                        if (! $owner instanceof Person || ! is_string($institutionId) || $institutionId === '') {
                            return;
                        }

                        app(PersonWorkflow::class)->attachAffiliation(
                            (string) $owner->getKey(),
                            $institutionId,
                            $data,
                        );

                        $action->success();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        TextInput::make('position'),
                        Toggle::make('is_primary'),
                        DatePicker::make('joined_at'),
                    ])
                    ->action(function (EditAction $action, Model $record, array $data): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->updateAffiliationForInstitution(
                            (string) $owner->getKey(),
                            (string) $record->getKey(),
                            $data,
                        );

                        $action->success();
                    }),
                DetachAction::make()
                    ->action(function (DetachAction $action, Model $record): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->detachAffiliations(
                            (string) $owner->getKey(),
                            [(string) $record->getKey()],
                        );

                        $action->success();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->action(function (DetachBulkAction $action, Collection $records): void {
                            $owner = $this->getOwnerRecord();

                            if (! $owner instanceof Person) {
                                return;
                            }

                            app(PersonWorkflow::class)->detachAffiliations(
                                (string) $owner->getKey(),
                                $records
                                    ->map(static fn (Model $record): string => (string) $record->getKey())
                                    ->values()
                                    ->all(),
                            );

                            $action->success();
                        }),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            try {
                                $deleted = app(InstitutionWorkflow::class)->delete(
                                    $records->modelKeys(),
                                );
                            } catch (Throwable $exception) {
                                report($exception);

                                $action->reportCompleteBulkProcessingFailure();

                                return;
                            }

                            if ($deleted > 0) {
                                $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                            }

                            if ($deleted < $records->count()) {
                                $action->reportBulkProcessingFailure();
                            }
                        }),
                ]),
            ]);
    }
}
