<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subdistricts;

use App\Filament\Resources\Geography\Concerns\HasGeographyDeletionGuard;
use App\Filament\Resources\Subdistricts\Pages\CreateSubdistrict;
use App\Filament\Resources\Subdistricts\Pages\EditSubdistrict;
use App\Filament\Resources\Subdistricts\Pages\ListSubdistricts;
use App\Filament\Resources\Subdistricts\Schemas\SubdistrictForm;
use App\Filament\Resources\Subdistricts\Tables\SubdistrictsTable;
use App\Models\Subdistrict;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class SubdistrictResource extends Resource
{
    use HasGeographyDeletionGuard;

    protected static ?string $model = Subdistrict::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 23;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SubdistrictForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SubdistrictsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSubdistricts::route('/'),
            'create' => CreateSubdistrict::route('/create'),
            'edit' => EditSubdistrict::route('/{record}/edit'),
        ];
    }
}
