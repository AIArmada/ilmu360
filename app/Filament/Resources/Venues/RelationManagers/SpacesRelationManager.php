<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Actions\Spaces\SaveSpaceAction;
use App\Filament\Resources\Spaces\Schemas\SpaceForm;
use App\Models\Space;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SpacesRelationManager extends RelationManager
{
    protected static string $relationship = 'spaces';

    public function form(Schema $schema): Schema
    {
        return SpaceForm::configure(
            $schema,
            includeInstitutions: false,
            venueId: (string) $this->getOwnerRecord()->getKey(),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('space_type')->badge()->sortable(),
                TextColumn::make('capacity')->numeric()->sortable()->placeholder('-'),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Space => app(SaveSpaceAction::class)->handle([
                        ...$data,
                        'venue_id' => (string) $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, Space $record): Space {
                        return app(SaveSpaceAction::class)->handle($data, $record);
                    }),
                DeleteAction::make(),
            ]);
    }
}
