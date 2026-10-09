<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFraud;

use App\Filament\Resources\RewardFraud\Pages\ListRewardFraudCases;
use App\Filament\Resources\RewardFraud\Tables\RewardFraudTable;
use App\Models\RewardAward;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RewardFraudResource extends Resource
{
    protected static ?string $model = RewardAward::class;

    protected static ?string $slug = 'reward-fraud-stewardship';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?string $navigationLabel = 'Fraud stewardship';

    protected static ?int $navigationSort = 16;

    #[\Override]
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('reward-fraud-steward');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('status', ['held', 'pending_review'])
            ->orderByDesc('decided_at');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardFraudTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardFraudCases::route('/'),
        ];
    }
}
