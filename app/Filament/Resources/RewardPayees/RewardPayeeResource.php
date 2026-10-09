<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayees;

use App\Filament\Resources\RewardPayees\Pages\ListRewardPayees;
use App\Filament\Resources\RewardPayees\Tables\RewardPayeesTable;
use App\Models\RewardPayee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardPayeeResource extends Resource
{
    protected static ?string $model = RewardPayee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Payees';

    protected static ?int $navigationSort = 11;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardPayeesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardPayees::route('/'),
        ];
    }
}
