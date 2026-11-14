<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings;

use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages\CreateAiModelPricing;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages\EditAiModelPricing;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages\ListAiModelPricings;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Schemas\AiModelPricingForm;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Tables\AiModelPricingsTable;
use App\Features\Ai\Models\AiModelPricing;
use App\Platform\Auditing\AuditsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiModelPricingResource extends Resource
{
    #[\Override]
    protected static ?string $model = AiModelPricing::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'System';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'model_pattern';

    #[\Override]
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
