<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationChannels\Pages;

use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Models\DonationChannel;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDonationChannel extends EditRecord
{
    protected static string $resource = DonationChannelResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DonationChannel::query()->findOrFail(app(DonationChannelWorkflow::class)->save($data, (string) $record->getKey()));
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Model $record): bool {
                    app(DonationChannelWorkflow::class)->delete([(string) $record->getKey()]);

                    return true;
                }),
        ];
    }
}
