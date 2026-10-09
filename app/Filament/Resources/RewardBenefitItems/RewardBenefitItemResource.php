<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitItems;

use App\Filament\Resources\RewardBenefitItems\Pages\ListRewardBenefitItems;
use App\Filament\Resources\RewardBenefitItems\Tables\RewardBenefitItemsTable;
use App\Models\RewardBenefitItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardBenefitItemResource extends Resource
{
    protected static ?string $model = RewardBenefitItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Benefit catalog';

    protected static ?int $navigationSort = 13;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardBenefitItemsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardBenefitItems::route('/'),
        ];
    }
}
