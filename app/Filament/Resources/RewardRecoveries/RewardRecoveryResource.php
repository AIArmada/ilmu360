<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardRecoveries;

use App\Filament\Resources\RewardRecoveries\Pages\ListRewardRecoveries;
use App\Filament\Resources\RewardRecoveries\Tables\RewardRecoveriesTable;
use App\Models\RewardRecovery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardRecoveryResource extends Resource
{
    protected static ?string $model = RewardRecovery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Recoveries';

    protected static ?int $navigationSort = 15;

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardRecoveriesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardRecoveries::route('/'),
        ];
    }
}
