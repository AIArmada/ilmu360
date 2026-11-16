<?php

declare(strict_types=1);

namespace App\Filament\Resources\Institutions\RelationManagers;

use App\Filament\Resources\Events\RelationManagers\EventsRelationManager as BaseEventsRelationManager;
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
