<?php

declare(strict_types=1);

namespace App\Filament\Resources\TitleIssuers;

use App\Filament\Resources\TitleIssuers\Pages\CreateTitleIssuer;
use App\Filament\Resources\TitleIssuers\Pages\EditTitleIssuer;
use App\Filament\Resources\TitleIssuers\Pages\ListTitleIssuers;
use App\Filament\Resources\TitleIssuers\Schemas\TitleIssuerForm;
use App\Filament\Resources\TitleIssuers\Tables\TitleIssuersTable;
use App\Models\TitleIssuer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TitleIssuerResource extends Resource
{
    protected static ?string $model = TitleIssuer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return TitleIssuerForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return TitleIssuersTable::configure($table);
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
            'index' => ListTitleIssuers::route('/'),
            'create' => CreateTitleIssuer::route('/create'),
            'edit' => EditTitleIssuer::route('/{record}/edit'),
        ];
    }
}
