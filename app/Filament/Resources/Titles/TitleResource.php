<?php

declare(strict_types=1);

namespace App\Filament\Resources\Titles;

use App\Filament\Resources\Titles\Pages\CreateTitle;
use App\Filament\Resources\Titles\Pages\EditTitle;
use App\Filament\Resources\Titles\Pages\ListTitles;
use App\Filament\Resources\Titles\Schemas\TitleForm;
use App\Filament\Resources\Titles\Tables\TitlesTable;
use App\Models\Title;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TitleResource extends Resource
{
    protected static ?string $model = Title::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return TitleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return TitlesTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTitles::route('/'),
            'create' => CreateTitle::route('/create'),
            'edit' => EditTitle::route('/{record}/edit'),
        ];
    }
}
