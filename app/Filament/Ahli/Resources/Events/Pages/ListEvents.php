<?php

namespace App\Filament\Ahli\Resources\Events\Pages;

use App\Filament\Ahli\Resources\Events\EventResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_advanced_program')
                ->label('Create Advanced Program')
                ->icon('heroicon-o-squares-plus')
                ->url(route('dashboard.events.create-advanced'))
                ->visible(fn (): bool => $this->canCreateAdvancedProgram()),
        ];
    }

    private function canCreateAdvancedProgram(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->institutions()->exists() || $user->speakers()->exists();
    }
}
