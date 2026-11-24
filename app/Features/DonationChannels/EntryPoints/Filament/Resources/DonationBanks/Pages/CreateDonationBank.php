<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages;

use App\Features\DonationChannels\Actions\SaveDonationBankAction;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDonationBank extends CreateRecord
{
    #[\Override]
    protected static string $resource = DonationBankResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveDonationBankAction::class)->handle($data);
    }
}
