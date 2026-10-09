<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms;

use App\Filament\Resources\RewardPrograms\Pages\CreateRewardProgram;
use App\Filament\Resources\RewardPrograms\Pages\ListRewardPrograms;
use App\Filament\Resources\RewardPrograms\Pages\ViewRewardProgram;
use App\Filament\Resources\RewardPrograms\Schemas\RewardProgramForm;
use App\Filament\Resources\RewardPrograms\Schemas\RewardProgramInfolist;
use App\Filament\Resources\RewardPrograms\Tables\RewardProgramsTable;
use App\Models\RewardProgram;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RewardProgramResource extends Resource
{
    protected static ?string $model = RewardProgram::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Rewards';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return RewardProgramForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return RewardProgramInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return RewardProgramsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRewardPrograms::route('/'),
            'create' => CreateRewardProgram::route('/create'),
            'view' => ViewRewardProgram::route('/{record}'),
        ];
    }
}
