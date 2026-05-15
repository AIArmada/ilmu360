<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inspirations;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Inspirations\Pages\CreateInspiration;
use App\Filament\Resources\Inspirations\Pages\EditInspiration;
use App\Filament\Resources\Inspirations\Pages\ListInspirations;
use App\Filament\Resources\Inspirations\Schemas\InspirationForm;
use App\Filament\Resources\Inspirations\Tables\InspirationsTable;
use App\Models\Inspiration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InspirationResource extends Resource
{
    protected static ?string $model = Inspiration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return InspirationForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InspirationsTable::configure($table);
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
            'index' => ListInspirations::route('/'),
            'create' => CreateInspiration::route('/create'),
            'edit' => EditInspiration::route('/{record}/edit'),
        ];
    }
}
