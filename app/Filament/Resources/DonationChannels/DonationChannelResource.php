<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationChannels;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\DonationChannels\Pages\CreateDonationChannel;
use App\Filament\Resources\DonationChannels\Pages\EditDonationChannel;
use App\Filament\Resources\DonationChannels\Pages\ListDonationChannels;
use App\Filament\Resources\DonationChannels\Schemas\DonationChannelForm;
use App\Filament\Resources\DonationChannels\Tables\DonationChannelsTable;
use App\Models\DonationChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DonationChannelResource extends Resource
{
    protected static ?string $model = DonationChannel::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return DonationChannelForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return DonationChannelsTable::configure($table);
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
            'index' => ListDonationChannels::route('/'),
            'create' => CreateDonationChannel::route('/create'),
            'edit' => EditDonationChannel::route('/{record}/edit'),
        ];
    }
}
