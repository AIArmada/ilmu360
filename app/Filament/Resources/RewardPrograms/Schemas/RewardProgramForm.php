<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Schemas;

use AIArmada\Organizations\Models\Organization;
use App\Models\Institution;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class RewardProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('family_key')
                ->label('Family key')
                ->helperText('Stable program family slug, 1–64 lowercase characters. Never changes after creation.')
                ->required()
                ->maxLength(64)
                ->regex('/^[a-z0-9][a-z0-9_\-]{0,63}$/'),
            TextInput::make('title')
                ->required()
                ->maxLength(191),
            Select::make('mode')
                ->options(RewardAdminPresenter::programModeOptions())
                ->required(),
            Select::make('owner_type')
                ->options([
                    'platform' => 'Platform',
                    'institution' => 'Institution',
                    'organization' => 'Organization',
                ])
                ->default('platform')
                ->required()
                ->live()
                ->afterStateUpdated(static function (Set $set): void {
                    $set('owner_record_id', null);
                }),
            Select::make('owner_record_id')
                ->label('Owner')
                ->helperText('Search by name. Platform programs need no owner.')
                ->searchable()
                ->required(fn (Get $get): bool => $get('owner_type') !== 'platform')
                ->disabled(fn (Get $get): bool => $get('owner_type') === 'platform' || blank($get('owner_type')))
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => self::ownerSearchResults(
                    (string) $get('owner_type'), $search,
                ))
                ->getOptionLabelUsing(static fn (Get $get, mixed $value): ?string => self::ownerOptionLabel(
                    (string) $get('owner_type'), $value,
                )),
            Textarea::make('purpose')
                ->helperText('Explicit non-empty scope, e.g. the community this program serves.')
                ->required()
                ->maxLength(191)
                ->rows(2),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function ownerSearchResults(string $ownerType, ?string $search): array
    {
        $search = mb_trim((string) $search);

        if ($ownerType === 'institution') {
            return Institution::query()
                ->when($search !== '', static fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')
                ->limit(25)
                ->pluck('name', 'id')
                ->all();
        }

        if ($ownerType === 'organization') {
            return Organization::query()
                ->when($search !== '', static fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')
                ->limit(25)
                ->pluck('name', 'id')
                ->all();
        }

        return [];
    }

    private static function ownerOptionLabel(string $ownerType, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if ($ownerType === 'institution') {
            $name = Institution::query()->whereKey($value)->value('name');

            return is_string($name) ? $name : null;
        }

        if ($ownerType === 'organization') {
            $name = Organization::query()->whereKey($value)->value('name');

            return is_string($name) ? $name : null;
        }

        return null;
    }
}
