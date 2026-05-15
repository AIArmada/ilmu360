<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationChannels\Pages;

use App\Filament\Resources\DonationChannels\DonationChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDonationChannel extends EditRecord
{
    protected static string $resource = DonationChannelResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
