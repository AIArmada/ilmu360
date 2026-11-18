<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers;

use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Schemas\DonationChannelForm;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Tables\DonationChannelsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DonationChannelsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'donationChannels';

    #[\Override]
    protected static ?string $title = 'Donation Channels';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return DonationChannelForm::configure($schema, withOwnerSection: false);
    }

    public function table(Table $table): Table
    {
        return DonationChannelsTable::configure($table, showOwnerColumn: false)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
