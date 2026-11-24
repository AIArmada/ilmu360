<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers;

use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\Filament\DonationChannelAdminComponents;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

    #[\Override]
    public function table(Table $table): Table
    {
        return app(DonationChannelAdminComponents::class)->configureTable($table, showOwnerColumn: false)
            ->recordActions([
                EditAction::make()
                    ->using(fn (array $data, Model $record): Model => $this->saveChannel($data, (string) $record->getKey())),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Model => $this->saveChannel([
                        ...$data,
                        'donatable_type' => (string) $this->getOwnerRecord()->getMorphClass(),
                        'donatable_id' => (string) $this->getOwnerRecord()->getKey(),
                    ])),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveChannel(array $data, ?string $channelId = null): Model
    {
        return $this->getRelationship()->findOrFail(app(DonationChannelWorkflow::class)->save($data, $channelId));
    }
}
