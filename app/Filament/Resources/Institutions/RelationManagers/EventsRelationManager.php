<?php

declare(strict_types=1);

namespace App\Filament\Resources\Institutions\RelationManagers;

use AIArmada\FilamentEvents\RelationManagers\EventsRelationManager as BaseEventsRelationManager;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;

class EventsRelationManager extends BaseEventsRelationManager
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
