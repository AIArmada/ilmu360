<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFunding;

use App\Filament\Resources\RewardFunding\Pages\ListRewardFunding;
use App\Filament\Resources\RewardFunding\Tables\RewardFundingTable;
use App\Models\RewardFundingEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardFundingResource extends Resource
{
    protected static ?string $model = RewardFundingEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Funding';

    protected static ?int $navigationSort = 10;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardFundingTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardFunding::route('/'),
        ];
    }
}
