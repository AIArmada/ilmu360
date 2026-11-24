<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages;

use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDonationBanks extends ListRecords
{
    #[\Override]
    protected static string $resource = DonationBankResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
