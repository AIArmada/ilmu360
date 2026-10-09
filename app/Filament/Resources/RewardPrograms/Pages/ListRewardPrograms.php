<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardPrograms extends ListRecords
{
    protected static string $resource = RewardProgramResource::class;
}
