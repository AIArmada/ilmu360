<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFraud\Pages;

use App\Filament\Resources\RewardFraud\RewardFraudResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardFraudCases extends ListRecords
{
    protected static string $resource = RewardFraudResource::class;
}
