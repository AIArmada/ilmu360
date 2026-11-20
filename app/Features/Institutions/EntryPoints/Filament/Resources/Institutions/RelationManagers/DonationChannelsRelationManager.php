<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers;

use App\Features\DonationChannels\Api\Filament\DonationChannelAdminComponents;
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
        return app(DonationChannelAdminComponents::class)->configureForm($schema, withOwnerSection: false);
    }

    public function table(Table $table): Table
    {
        return app(DonationChannelAdminComponents::class)->configureTable($table, showOwnerColumn: false)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
