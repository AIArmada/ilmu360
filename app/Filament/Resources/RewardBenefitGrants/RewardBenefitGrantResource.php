<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitGrants;

use App\Filament\Resources\RewardBenefitGrants\Pages\ListRewardBenefitGrants;
use App\Filament\Resources\RewardBenefitGrants\Tables\RewardBenefitGrantsTable;
use App\Models\RewardBenefitGrant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardBenefitGrantResource extends Resource
{
    protected static ?string $model = RewardBenefitGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Benefit grants';

    protected static ?int $navigationSort = 14;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardBenefitGrantsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardBenefitGrants::route('/'),
        ];
    }
}
