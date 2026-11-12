<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationChannels\Pages;

use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Models\DonationChannel;
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
