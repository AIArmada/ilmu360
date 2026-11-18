<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Pages;

use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Features\DonationChannels\Models\DonationChannel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDonationChannel extends CreateRecord
{
    #[\Override]
    protected static string $resource = DonationChannelResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return DonationChannel::query()->findOrFail(app(DonationChannelWorkflow::class)->save($data));
    }
}
