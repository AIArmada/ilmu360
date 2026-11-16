<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects;

use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages\CreateSlugRedirect;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages\EditSlugRedirect;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages\ListSlugRedirects;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Pages\ViewSlugRedirect;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Schemas\SlugRedirectForm;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Schemas\SlugRedirectInfolist;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Tables\SlugRedirectsTable;
use App\Features\SlugRedirects\Models\SlugRedirect;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SlugRedirectResource extends Resource
{
    #[\Override]
    protected static ?string $model = SlugRedirect::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    protected static ?string $modelLabel = 'Slug Redirect';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Slug Redirects';

    #[\Override]
    protected static ?string $navigationLabel = 'Slug Redirects';

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'System';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SlugRedirectForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SlugRedirectInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SlugRedirectsTable::configure($table);
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
            'index' => ListSlugRedirects::route('/'),
            'create' => CreateSlugRedirect::route('/create'),
            'view' => ViewSlugRedirect::route('/{record}'),
            'edit' => EditSlugRedirect::route('/{record}/edit'),
        ];
    }
}
