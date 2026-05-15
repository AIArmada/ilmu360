<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationChannels\Pages;

use App\Filament\Resources\DonationChannels\DonationChannelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDonationChannel extends CreateRecord
{
    protected static string $resource = DonationChannelResource::class;
}
