<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks;

use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages\CreateDonationBank;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages\EditDonationBank;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages\ListDonationBanks;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Schemas\DonationBankForm;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Tables\DonationBanksTable;
use App\Features\DonationChannels\Models\DonationBank;
use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Auditing\AuditsRelationManager;
use BackedEnum;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Global-admin-only management of the country-scoped donation bank catalog.
 *
 * The catalog is deliberately excluded from the admin API/MCP surface: it is
 * platform reference data edited in Filament, and the bank option endpoints
 * continue to serve resolved catalogs to schema-driven writers.
 *
 * @extends FilamentResource<DonationBank>
 */
class DonationBankResource extends FilamentResource implements ExcludedFromAdminApi
{
    #[\Override]
    protected static ?string $model = DonationBank::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'System';

    #[\Override]
    protected static ?string $navigationLabel = 'Donation banks';

    #[\Override]
    protected static ?string $modelLabel = 'Donation bank';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Donation banks';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return DonationBankForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DonationBanksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDonationBanks::route('/'),
            'create' => CreateDonationBank::route('/create'),
            'edit' => EditDonationBank::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<DonationBank>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('country');
    }
}
