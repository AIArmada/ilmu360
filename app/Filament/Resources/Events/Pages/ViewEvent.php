<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use AIArmada\FilamentEvents\Pages\EventPublicPreview;
use App\Filament\Resources\Events\EventResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEvent extends ViewRecord
{
    #[\Override]
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('publicPreview')
                ->label('Public Preview')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => EventPublicPreview::getUrl(['eventId' => $this->record->getKey()])),
        ];
    }
}
