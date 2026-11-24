<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages;

use App\Features\DonationChannels\Actions\SaveDonationBankAction;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankAdminActions;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankResource;
use App\Features\DonationChannels\Models\DonationBank;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditDonationBank extends EditRecord
{
    #[\Override]
    protected static string $resource = DonationBankResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof DonationBank) {
            throw new LogicException('The edited record is not a donation bank.');
        }

        return app(SaveDonationBankAction::class)->handle($data, $record);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DonationBankAdminActions::retire(),
            DonationBankAdminActions::reactivate(),
            DonationBankAdminActions::delete(),
        ];
    }
}
