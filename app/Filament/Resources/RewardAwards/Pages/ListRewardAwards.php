<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAwards\Pages;

use App\Filament\Resources\RewardAwards\RewardAwardResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardAwards extends ListRecords
{
    protected static string $resource = RewardAwardResource::class;
}
