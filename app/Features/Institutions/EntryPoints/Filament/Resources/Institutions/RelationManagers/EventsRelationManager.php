<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers;

use App\Platform\Admin\Filament\RelationManagers\EventRecordsRelationManager;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;

class EventsRelationManager extends EventRecordsRelationManager
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
