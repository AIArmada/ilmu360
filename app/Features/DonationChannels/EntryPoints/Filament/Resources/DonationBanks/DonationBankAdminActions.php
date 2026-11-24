<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks;

use App\Features\DonationChannels\Actions\DeleteDonationBankAction;
use App\Features\DonationChannels\Actions\TransitionDonationBankStatusAction;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;
use App\Features\DonationChannels\Queries\DonationBankReferences;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle actions shared by the donation bank table and edit page.
 *
 * Retirement keeps stored donation channel references untouched and only
 * removes the entry from new selection; deletion is offered for unreferenced
 * entries alone.
 */
class DonationBankAdminActions
{
    public static function retire(): Action
    {
        return Action::make('retire')
            ->label('Retire')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retire donation bank')
            ->modalDescription('Existing donation channels keep this bank; it is removed from new selection and can be reactivated later.')
            ->visible(static fn (DonationBank $record): bool => $record->status === DonationBankStatus::Active)
            ->action(static function (DonationBank $record): void {
                app(TransitionDonationBankStatusAction::class)->handle($record, DonationBankStatus::Retired);
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Reactivate donation bank')
            ->modalDescription('The bank becomes selectable again for new donation channels.')
            ->visible(static fn (DonationBank $record): bool => $record->status === DonationBankStatus::Retired)
            ->action(static function (DonationBank $record): void {
                app(TransitionDonationBankStatusAction::class)->handle($record, DonationBankStatus::Active);
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(static fn (DonationBank $record): bool => ! self::isReferenced($record))
            ->using(static function (DonationBank $record): bool {
                try {
                    app(DeleteDonationBankAction::class)->handle($record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('The donation bank could not be deleted'))
                        ->body($exception->getMessage())
                        ->send();

                    return false;
                }

                return true;
            });
    }

    private static function isReferenced(DonationBank $record): bool
    {
        $loaded = $record->hasAttribute('referenced_channels_exists')
            ? $record->getAttribute('referenced_channels_exists')
            : null;

        if (is_bool($loaded)) {
            return $loaded;
        }

        return app(DonationBankReferences::class)->exists($record);
    }
}
