<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Filament;

use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Donation channel admin form and table composition boundary.
 *
 * Institution relation managers reuse the owner's configured components
 * without importing the DonationChannels Filament entrypoints.
 */
interface DonationChannelAdminComponents
{
    public function configureForm(Schema $schema, bool $withOwnerSection = true): Schema;

    public function configureTable(Table $table, bool $showOwnerColumn = true): Table;
}
