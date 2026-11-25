<?php

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\Schemas;

use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelOptions;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                            Person::class => 'Person',
                            Event::class => 'Event',
                            Organization::class => 'Organization',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('donatable_id', null)),
                    Select::make('donatable_id')
                        ->label('Recipient Entity')
                        ->searchable()
                        ->required()
                        ->live()
                        ->getSearchResultsUsing(function (string $search, Get $get): array {
                            $type = $get('donatable_type');
                            $column = match ($type) {
                                Event::class => 'title',
                                Institution::class, Person::class, Organization::class => 'name',
                                default => null,
                            };

                            if ($column === null) {
                                return [];
                            }

                            $query = $type::query();
                            $trimmed = trim($search);

                            if ($trimmed !== '') {
                                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($trimmed));
                                $query->whereRaw("LOWER({$column}) LIKE ?", ["%{$escaped}%"]);
                            }

                            /** @var array<string, string> */
                            return $query
                                ->orderBy($column)
                                ->limit(50)
                                ->pluck($column, 'id')
                                ->all();
                        })
                        ->getOptionLabelUsing(function (mixed $value, Get $get): ?string {
                            if (! is_string($value) || $value === '') {
                                return null;
                            }

                            $type = $get('donatable_type');
                            $column = match ($type) {
                                Event::class => 'title',
                                Institution::class, Person::class, Organization::class => 'name',
                                default => null,
                            };

                            if ($column === null) {
                                return null;
                            }

                            $label = $type::query()->whereKey($value)->value($column);

                            return is_string($label) && $label !== '' ? $label : null;
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
                        'duitnow' => __('DuitNow Transfer'),
                        'ewallet' => 'E-Wallet',
                    ])
                    ->required()
                    ->live(),
            ])->columns(3);

        $components[] = Section::make('Payment Info')
            ->schema([
                // Bank Account Fields
                Select::make('currency')
                    ->label('Currency')
                    ->options(fn (): array => app(DonationChannelCountryProfiles::class)->currencyOptions())
                    ->searchable()
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->native(false),
                Select::make('bank_code')
                    ->label('Bank')
                    ->options(fn (Get $get, ?DonationChannel $record = null, mixed $livewire = null): array => self::bankOptions($get, $record, $livewire))
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->required(fn (Get $get) => $get('method') === 'bank_account')
                    ->helperText('Select a bank from the owner country bank catalog. The stored bank name is derived from the selection.'),
                TextInput::make('account_number')
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->required(fn (Get $get) => $get('method') === 'bank_account' && blank($get('iban'))),
                TextInput::make('iban')
                    ->label('IBAN')
                    ->maxLength(34)
                    ->visible(fn (Get $get) => $get('method') === 'bank_account')
                    ->required(fn (Get $get) => $get('method') === 'bank_account' && blank($get('account_number'))),
                TextInput::make('swift_bic')
                    ->label('SWIFT / BIC')
                    ->maxLength(11)
                    ->visible(fn (Get $get) => $get('method') === 'bank_account'),
                TextInput::make('routing_code')
                    ->label('Routing / sort code')
                    ->maxLength(20)
                    ->visible(fn (Get $get, ?DonationChannel $record = null, mixed $livewire = null): bool => $get('method') === 'bank_account'
                        && self::supportsRoutingCodes($get, $record, $livewire))
                    ->helperText('Local clearing or branch code used by some international banks, for example an Indonesian bank code or a Singapore branch code.'),

                // DuitNow Fields
                Select::make('duitnow_type')
                    ->label(__('DuitNow ID Type'))
                    ->options(DonationChannelOptions::duitNowIdTypes())
                    ->native(false)
                    ->visible(fn (Get $get) => $get('method') === 'duitnow')
                    ->required(fn (Get $get) => $get('method') === 'duitnow'),
                TextInput::make('duitnow_value')
                    ->label(__('DuitNow ID'))
                    ->visible(fn (Get $get) => $get('method') === 'duitnow')
                    ->required(fn (Get $get) => $get('method') === 'duitnow'),

                // E-Wallet Fields
                Select::make('ewallet_provider')
                    ->label(__('E-Wallet Provider'))
                    ->options(DonationChannelOptions::EWALLET_PROVIDERS)
                    ->native(false)
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
                    ->options(DonationChannelStatus::class)
                    ->default(DonationChannelStatus::Pending)
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

    /**
     * @return array<array-key, string>
     */
    private static function bankOptions(Get $get, ?DonationChannel $record = null, mixed $livewire = null): array
    {
        $owner = self::resolveOwnerModel($get, $record, $livewire);

        if (! $owner instanceof Model) {
            return [];
        }

        $banks = app(DonationChannelCountryProfiles::class)->forOwner($owner)->banks;

        $options = $banks->options();
        $storedCode = $record instanceof DonationChannel ? $record->getAttribute('bank_code') : null;

        if (is_string($storedCode) && $storedCode !== '' && ! array_key_exists($storedCode, $options)) {
            $name = $banks->reference($storedCode)?->name;
            $options[$storedCode] = $name === null ? $storedCode : $name.' (retired)';
        }

        return $options;
    }

    private static function supportsRoutingCodes(Get $get, ?DonationChannel $record = null, mixed $livewire = null): bool
    {
        $owner = self::resolveOwnerModel($get, $record, $livewire);

        return $owner instanceof Model
            && app(DonationChannelCountryProfiles::class)->forOwner($owner)->supports_routing_codes;
    }

    private static function resolveOwnerModel(Get $get, ?DonationChannel $record = null, mixed $livewire = null): ?Model
    {
        if (is_object($livewire) && method_exists($livewire, 'getOwnerRecord')) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Model) {
                return $ownerRecord;
            }
        }

        [$ownerType, $ownerId] = self::resolveOwnerContext($get, $record, $livewire);

        if (blank($ownerType) || blank($ownerId)) {
            return null;
        }

        $modelClass = self::ownerModelClass($ownerType);

        if ($modelClass === null) {
            return null;
        }

        $owner = $modelClass::query()->find($ownerId);

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * @return class-string<Model>|null
     */
    private static function ownerModelClass(string $ownerType): ?string
    {
        foreach ([Institution::class, Person::class, Event::class, Organization::class] as $candidate) {
            if ($ownerType === $candidate || $ownerType === (new $candidate)->getMorphClass()) {
                return $candidate;
            }
        }

        return null;
    }
}
