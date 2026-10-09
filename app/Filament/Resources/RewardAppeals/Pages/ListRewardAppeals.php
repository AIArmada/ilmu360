<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAppeals\Pages;

use App\Filament\Resources\RewardAppeals\RewardAppealResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardAppeals extends ListRecords
{
    protected static string $resource = RewardAppealResource::class;
}
