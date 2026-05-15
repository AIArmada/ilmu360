<?php

namespace App\Filament\Resources\DonationChannels\Schemas;

use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class DonationChannelForm
{
    public static function configure(Schema $schema, bool $withOwnerSection = true): Schema
    {
        $components = [];

        if ($withOwnerSection) {
            $components[] = Section::make('Owner')
                ->schema([
                    Select::make('donatable_type')
                        ->options([
                            Institution::class => 'Institution',
                            Speaker::class => 'Speaker',
                            Event::class => 'Event',
                        ])
                        ->required()
                        ->live(),
                    Select::make('donatable_id')
                        ->label('Recipient Entity')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->options(function (Get $get) {
                            $type = $get('donatable_type');
                            if (! $type) {
                                return [];
                            }

                            return $type::query()->pluck('name', 'id');
                        }),
                ])->columns(2);
        }

        $components[] = Section::make('Account Details')
            ->schema([
                TextInput::make('label')
                    ->placeholder('e.g. Tabung Masjid, Dana Pembangunan'),
                TextInput::make('recipient')
                    ->required()
                    ->placeholder('Full name on account'),
                Select::make('method')
                    ->options([
                        'bank_account' => 'Bank Account',
                        'duitnow' => 'DuitNow',
                        'ewallet' => 'E-Wallet',
                    ])
                    ->required()
                    ->live(),
            ])->columns(3);

        $components[] = Section::make('Payment Info')
            ->schema([
                // Bank Account Fields
                TextInput::make('bank_name')
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->required(fn (Get $get) => $get('method') === 'bank_account'),
                TextInput::make('bank_code')
                    ->visible(fn (Get $get) => $get('method') === 'bank_account'),
                TextInput::make('account_number')
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->required(fn (Get $get) => $get('method') === 'bank_account'),

                // DuitNow Fields
                TextInput::make('duitnow_type')
                    ->visible(fn (Get $get) => $get('method') === 'duitnow')
                    ->required(fn (Get $get) => $get('method') === 'duitnow'),
                TextInput::make('duitnow_value')
                    ->visible(fn (Get $get) => $get('method') === 'duitnow')
                    ->required(fn (Get $get) => $get('method') === 'duitnow'),

                // E-Wallet Fields
                TextInput::make('ewallet_provider')
                    ->visible(fn (Get $get) => $get('method') === 'ewallet')
                    ->required(fn (Get $get) => $get('method') === 'ewallet'),
                TextInput::make('ewallet_handle')
                    ->visible(fn (Get $get) => $get('method') === 'ewallet'),
                Textarea::make('ewallet_qr_payload')
                    ->visible(fn (Get $get) => $get('method') === 'ewallet'),
            ])->columns(3);

        $components[] = Section::make('QR Code')
            ->schema([
                SpatieMediaLibraryFileUpload::make('qr')
                    ->label('QR Image')
                    ->collection('qr')
                    ->image()
                    ->imageEditor()
                    ->conversion('thumb')
                    ->helperText('Upload an official payment QR image.'),
            ]);

        $components[] = Section::make('Verification')
            ->schema([
                Select::make('status')
                    ->options([
                        'unverified' => 'Unverified',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        'inactive' => 'Inactive',
                    ])
                    ->default('unverified')
                    ->required(),
                Toggle::make('confirm_default_replacement')
                    ->label('Replace existing default')
                    ->helperText('Enable this to replace the current default channel for this owner.')
                    ->dehydrated(false)
                    ->live()
                    ->visible(fn (Get $get, ?DonationChannel $record = null, mixed $livewire = null): bool => self::hasAnotherDefaultChannel($get, $record, $livewire)
                        && ! (bool) $get('is_default')),
                Toggle::make('is_default')
                    ->label('Default')
                    ->live()
                    ->disabled(function (Get $get, ?DonationChannel $record = null, mixed $livewire = null): bool {
                        if ($record?->is_default) {
                            return false;
                        }

                        return self::hasAnotherDefaultChannel($get, $record, $livewire)
                            && ! (bool) $get('confirm_default_replacement');
                    })
                    ->helperText(function (Get $get, ?DonationChannel $record = null, mixed $livewire = null): string {
                        if (
                            self::hasAnotherDefaultChannel($get, $record, $livewire)
                            && ! (bool) $get('confirm_default_replacement')
                            && ! $record?->is_default
                        ) {
                            return 'Another default channel already exists. Enable "Replace existing default" first.';
                        }

                        return 'Only one default channel is allowed for this owner.';
                    }),
                Textarea::make('reference_note')
                    ->label('Reference Note')
                    ->columnSpanFull(),
            ])->columns(2);

        return $schema
            ->components($components);
    }

    private static function hasAnotherDefaultChannel(Get $get, ?DonationChannel $record = null, mixed $livewire = null): bool
    {
        [$ownerType, $ownerId] = self::resolveOwnerContext($get, $record, $livewire);

        if (blank($ownerType) || blank($ownerId)) {
            return false;
        }

        $query = DonationChannel::query()
            ->where('donatable_type', $ownerType)
            ->where('donatable_id', $ownerId)
            ->where('is_default', true);

        if ($record?->exists) {
            $query->whereKeyNot($record->getKey());
        }

        return $query->exists();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolveOwnerContext(Get $get, ?DonationChannel $record = null, mixed $livewire = null): array
    {
        $ownerType = $get('donatable_type');
        $ownerId = $get('donatable_id');

        if (filled($ownerType) && filled($ownerId)) {
            return [self::normalizeOwnerType((string) $ownerType), (string) $ownerId];
        }

        if ($record?->exists && filled($record->donatable_type) && filled($record->donatable_id)) {
            return [(string) $record->donatable_type, (string) $record->donatable_id];
        }

        if (is_object($livewire) && method_exists($livewire, 'getOwnerRecord')) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Model && filled($ownerRecord->getKey())) {
                return [(string) $ownerRecord->getMorphClass(), (string) $ownerRecord->getKey()];
            }
        }

        return [null, null];
    }

    private static function normalizeOwnerType(string $ownerType): string
    {
        if (class_exists($ownerType) && is_subclass_of($ownerType, Model::class)) {
            $model = new $ownerType;

            return (string) $model->getMorphClass();
        }

        return $ownerType;
    }
}
