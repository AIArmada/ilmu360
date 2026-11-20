<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels;

use App\Features\DonationChannels\Api\Filament\DonationChannelAdminComponents;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Schemas\DonationChannelForm;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Tables\DonationChannelsTable;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final readonly class DonationChannelAdminComponentsAdapter implements DonationChannelAdminComponents
{
    public function configureForm(Schema $schema, bool $withOwnerSection = true): Schema
    {
        return DonationChannelForm::configure($schema, $withOwnerSection);
    }

    public function configureTable(Table $table, bool $showOwnerColumn = true): Table
    {
        return DonationChannelsTable::configure($table, $showOwnerColumn);
    }
}
