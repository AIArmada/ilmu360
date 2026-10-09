<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayouts;

use App\Filament\Resources\RewardPayouts\Pages\ListRewardPayouts;
use App\Filament\Resources\RewardPayouts\Tables\RewardPayoutsTable;
use App\Models\RewardPayoutRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardPayoutResource extends Resource
{
    protected static ?string $model = RewardPayoutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Payouts';

    protected static ?int $navigationSort = 12;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardPayoutsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardPayouts::route('/'),
        ];
    }
}
