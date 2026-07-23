<?php

namespace App\Filament\Resources\TitleIssuers\Schemas;

use App\Enums\IssuerType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TitleIssuerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Issuer Details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('issuer_name')
                            ->label(__('Issuer Name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('issuer_type')
                            ->label(__('Issuer Type'))
                            ->options(IssuerType::class)
                            ->required(),
                        TextInput::make('country_id')
                            ->label(__('Country ID')),
                        TextInput::make('institution_id')
                            ->label(__('Institution ID')),
                    ]),
            ]);
    }
}
