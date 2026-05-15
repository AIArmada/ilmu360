<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiModelPricings;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\AiModelPricings\Pages\CreateAiModelPricing;
use App\Filament\Resources\AiModelPricings\Pages\EditAiModelPricing;
use App\Filament\Resources\AiModelPricings\Pages\ListAiModelPricings;
use App\Filament\Resources\AiModelPricings\Schemas\AiModelPricingForm;
use App\Filament\Resources\AiModelPricings\Tables\AiModelPricingsTable;
use App\Models\AiModelPricing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiModelPricingResource extends Resource
{
    protected static ?string $model = AiModelPricing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $recordTitleAttribute = 'model_pattern';

    protected static ?int $navigationSort = 99;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return AiModelPricingForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return AiModelPricingsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAiModelPricings::route('/'),
            'create' => CreateAiModelPricing::route('/create'),
            'edit' => EditAiModelPricing::route('/{record}/edit'),
        ];
    }
}
