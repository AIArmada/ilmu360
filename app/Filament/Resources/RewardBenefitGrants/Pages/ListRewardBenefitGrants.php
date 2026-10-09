<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitGrants\Pages;

use App\Filament\Resources\RewardBenefitGrants\RewardBenefitGrantResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardBenefitGrants extends ListRecords
{
    protected static string $resource = RewardBenefitGrantResource::class;
}
