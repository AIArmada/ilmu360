<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAppeals;

use App\Filament\Resources\RewardAppeals\Pages\ListRewardAppeals;
use App\Filament\Resources\RewardAppeals\Pages\ViewRewardAppeal;
use App\Filament\Resources\RewardAppeals\Schemas\RewardAppealInfolist;
use App\Filament\Resources\RewardAppeals\Tables\RewardAppealsTable;
use App\Models\RewardAppeal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardAppealResource extends Resource
{
    protected static ?string $model = RewardAppeal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?int $navigationSort = 3;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return RewardAppealInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardAppealsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardAppeals::route('/'),
            'view' => ViewRewardAppeal::route('/{record}'),
        ];
    }
}
