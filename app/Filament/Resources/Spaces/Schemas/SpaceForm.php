<?php

namespace App\Filament\Resources\Spaces\Schemas;

use AIArmada\Events\Models\VenueSpaceType;
use App\Models\Institution;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SpaceForm
{
    public static function configure(Schema $schema, bool $includeInstitutions = true, ?string $venueId = null): Schema
    {
        $components = [
            Section::make('Space Details')
                ->components([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                            if (filled($get('slug'))) {
                                return;
                            }

                            $set('slug', Str::slug((string) $state));
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $venueId === null
                                ? $rule->whereNull('venue_id')
                                : $rule->where('venue_id', $venueId),
                        ),
                    TextInput::make('code')
                        ->maxLength(255),
                    TextInput::make('capacity')
                        ->numeric()
                        ->minValue(1),
                    Select::make('space_type')
                        ->options(fn (): array => VenueSpaceType::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'code')
                            ->all())
                        ->searchable(),
                    TextInput::make('level')
                        ->label('Floor / Level')
                        ->maxLength(255),
                    TextInput::make('unit_no')
                        ->label('Unit No.')
                        ->maxLength(255),
                    TextInput::make('block')
                        ->maxLength(255),
                    TextInput::make('wing')
                        ->maxLength(255),
                    Select::make('status')
                        ->options([
                            'active' => 'Active',
                            'inactive' => 'Inactive',
                        ])
                        ->default('active')
                        ->required(),
                    Select::make('visibility')
                        ->options([
                            'public' => 'Public',
                            'unlisted' => 'Unlisted',
                            'private' => 'Private',
                        ])
                        ->default('public')
                        ->required(),
                ])
                ->columns(2),

        ];

        if ($includeInstitutions) {
            $components[] = Section::make('Institutions')
                ->components([
                    Select::make('institutions')
                        ->placeholder(__('Select institution'))
                        ->relationship('institutions', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Optional: Link this space to one or more institutions.'),
                    Repeater::make('institution_space_overrides')
                        ->label(__('Institution capacity overrides'))
                        ->schema([
                            Select::make('institution_id')
                                ->options(fn (): array => Institution::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required(),
                            TextInput::make('capacity')
                                ->numeric()
                                ->minValue(1)
                                ->nullable(),
                        ])
                        ->columns(2)
                        ->default([])
                        ->helperText(__('Replace or clear institution-specific capacity overrides.')),
                ]);
        }

        return $schema->components($components);
    }
}
