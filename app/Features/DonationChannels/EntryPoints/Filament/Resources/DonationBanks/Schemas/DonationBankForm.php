<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Schemas;

use App\Features\DonationChannels\Models\DonationBank;
use App\Features\DonationChannels\Queries\DonationBankReferences;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DonationBankForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bank')
                    ->schema([
                        Select::make('country_id')
                            ->label('Country')
                            ->relationship('country', 'name')
                            ->searchable(['name'])
                            ->required()
                            ->disabled(static fn (?DonationBank $record): bool => self::isReferenced($record))
                            ->helperText(static fn (?DonationBank $record): string => self::isReferenced($record)
                                ? __('Donation channels already reference this bank, so its country cannot change.')
                                : __('Canonical country this bank belongs to. The code is unique inside it.')),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(32)
                            ->disabled(static fn (?DonationBank $record): bool => self::isReferenced($record))
                            ->helperText(static fn (?DonationBank $record): string => self::isReferenced($record)
                                ? __('Donation channels already reference this bank, so its code cannot change.')
                                : __('Stable clearing code from the country bank list, for example an FPX, BI-FAST or FAST code.')),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('Catalog display name derived onto every new donation channel that selects this bank. Renaming never rewrites stored channels.')),
                        TextInput::make('source_url')
                            ->label('Source URL')
                            ->required()
                            ->url()
                            ->maxLength(2048)
                            ->helperText(__('Authoritative source this bank code and display name were verified against.')),
                    ])
                    ->columns(2),
            ]);
    }

    private static function isReferenced(?DonationBank $record): bool
    {
        return $record instanceof DonationBank
            && $record->exists
            && app(DonationBankReferences::class)->exists($record);
    }
}
