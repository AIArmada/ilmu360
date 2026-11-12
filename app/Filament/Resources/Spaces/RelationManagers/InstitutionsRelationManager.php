<?php

declare(strict_types=1);

namespace App\Filament\Resources\Spaces\RelationManagers;

use App\Features\Directory\Api\InstitutionSpaceLinks;
use App\Filament\Resources\Institutions\InstitutionResource;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class InstitutionsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'institutions';

    #[\Override]
    protected static ?string $relatedResource = InstitutionResource::class;

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
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->action(function (AttachAction $action, array $data): void {
                        $institutionIds = is_array($data['recordId'] ?? null)
                            ? $data['recordId']
                            : [$data['recordId'] ?? null];

                        foreach ($institutionIds as $institutionId) {
                            if (! is_scalar($institutionId) || (string) $institutionId === '') {
                                continue;
                            }

                            app(InstitutionSpaceLinks::class)->attach(
                                (string) $institutionId,
                                (string) $this->getOwnerRecord()->getKey(),
                            );
                        }

                        $action->success();
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->action(function (DetachAction $action, Model $record): void {
                        app(InstitutionSpaceLinks::class)->detach(
                            (string) $record->getKey(),
                            (string) $this->getOwnerRecord()->getKey(),
                        );

                        $action->success();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->action(function (DetachBulkAction $action, Collection $records): void {
                            $spaceId = (string) $this->getOwnerRecord()->getKey();

                            foreach ($records as $record) {
                                if (! $record instanceof Model) {
                                    continue;
                                }

                                app(InstitutionSpaceLinks::class)->detach(
                                    (string) $record->getKey(),
                                    $spaceId,
                                );
                            }

                            $action->success();
                        }),
                ]),
            ]);
    }
}
