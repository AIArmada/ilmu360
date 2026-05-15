<?php

declare(strict_types=1);

namespace App\Filament\Resources\Institutions\RelationManagers;

use App\Filament\Resources\DonationChannels\Schemas\DonationChannelForm;
use App\Filament\Resources\DonationChannels\Tables\DonationChannelsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DonationChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'donationChannels';

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
