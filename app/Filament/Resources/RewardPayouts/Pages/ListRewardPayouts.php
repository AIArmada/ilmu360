<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayouts\Pages;

use App\Filament\Resources\RewardPayouts\RewardPayoutResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardPayouts extends ListRecords
{
    protected static string $resource = RewardPayoutResource::class;
}
