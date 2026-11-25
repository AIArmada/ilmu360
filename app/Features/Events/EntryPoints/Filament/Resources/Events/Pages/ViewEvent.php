<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Pages\EventPublicPreview;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Filament\Support\EventModerationActions;
use App\Features\Events\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEvent extends ViewRecord
{
    #[\Override]
    protected static string $resource = EventResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    protected function getHeaderActions(): array
    {
        $refreshRecord = function (Event $record): void {
            $fresh = $record->fresh();

            if ($fresh instanceof Event) {
                $this->record = $fresh;
            }
        };

        return [
            EditAction::make(),
            EventModerationActions::approveAction($refreshRecord),
            EventModerationActions::requestChangesAction($refreshRecord),
            EventModerationActions::rejectAction($refreshRecord),
            EventModerationActions::cancelAction($refreshRecord),
            EventModerationActions::remoderateAction($refreshRecord),
            EventModerationActions::reconsiderAction($refreshRecord),
            EventModerationActions::revertToDraftAction($refreshRecord),
            EventModerationActions::returnToPendingAction($refreshRecord),
            Action::make('publicPreview')
                ->label('Public Preview')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => EventPublicPreview::getUrl(['eventId' => $this->record->getKey()])),
        ];
    }
}
