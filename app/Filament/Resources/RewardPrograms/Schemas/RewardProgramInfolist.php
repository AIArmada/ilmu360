<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Schemas;

use App\Models\RewardEnrollment;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardAdminPresenter;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RewardProgramInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Program')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('title'),
                        TextEntry::make('family_key'),
                        TextEntry::make('mode')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::programModeOptions()[(string) $state->value] ?? (string) $state->value),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::statusLabel($state))
                            ->color(fn (mixed $state): string => RewardAdminPresenter::statusColor($state)),
                        TextEntry::make('purpose')->columnSpan(2),
                        TextEntry::make('ready_at')->dateTime()->placeholder('-'),
                        TextEntry::make('active_at')->dateTime()->placeholder('-'),
                        TextEntry::make('paused_at')->dateTime()->placeholder('-'),
                        TextEntry::make('closed_at')->dateTime()->placeholder('-'),
                    ]),
                ]),
            Section::make('Current version')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('current_version')
                            ->label('Version')
                            ->state(fn (RewardProgram $record): string => self::currentVersionLabel($record)),
                        TextEntry::make('version_effective_from')
                            ->label('Effective from')
                            ->state(fn (RewardProgram $record): ?string => self::currentVersionEffectiveFrom($record))
                            ->placeholder('-'),
                        TextEntry::make('material_terms_hash')
                            ->label('Material terms hash')
                            ->state(fn (RewardProgram $record): ?string => self::currentVersionHash($record))
                            ->placeholder('-'),
                        TextEntry::make('version_count')
                            ->label('Published versions')
                            ->state(fn (RewardProgram $record): int => RewardProgramVersion::query()->where('program_id', $record->getKey())->count()),
                        TextEntry::make('enrollment_count')
                            ->label('Enrollments')
                            ->state(fn (RewardProgram $record): int => RewardEnrollment::query()->where('program_id', $record->getKey())->count()),
                    ]),
                ]),
        ]);
    }

    private static function currentVersionLabel(RewardProgram $record): string
    {
        $version = self::currentVersion($record);

        return $version instanceof RewardProgramVersion ? 'v'.$version->version : 'None published';
    }

    private static function currentVersionEffectiveFrom(RewardProgram $record): ?string
    {
        $version = self::currentVersion($record);

        if (! $version instanceof RewardProgramVersion) {
            return null;
        }

        return CarbonImmutable::parse($version->effective_from)->setTimezone('UTC')->toIso8601String();
    }

    private static function currentVersionHash(RewardProgram $record): ?string
    {
        $version = self::currentVersion($record);

        return $version instanceof RewardProgramVersion ? (string) $version->material_terms_hash : null;
    }

    private static function currentVersion(RewardProgram $record): ?RewardProgramVersion
    {
        if ($record->current_version_id === null) {
            return null;
        }

        return RewardProgramVersion::query()->whereKey((string) $record->current_version_id)->first();
    }
}
