<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAwards;

use App\Filament\Resources\RewardAwards\Pages\ListRewardAwards;
use App\Filament\Resources\RewardAwards\Pages\ViewRewardAward;
use App\Filament\Resources\RewardAwards\Schemas\RewardAwardInfolist;
use App\Filament\Resources\RewardAwards\Tables\RewardAwardsTable;
use App\Models\RewardAward;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardAwardResource extends Resource
{
    protected static ?string $model = RewardAward::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?int $navigationSort = 2;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return RewardAwardInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardAwardsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardAwards::route('/'),
            'view' => ViewRewardAward::route('/{record}'),
        ];
    }
}
