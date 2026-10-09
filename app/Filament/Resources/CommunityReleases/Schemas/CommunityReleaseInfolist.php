<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityReleases\Schemas;

use App\Models\CommunityRelease;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CommunityReleaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Release')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('release_key')->label('Month'),
                        TextEntry::make('period_start')->label('From')->date(),
                        TextEntry::make('period_end')->label('To')->date(),
                        TextEntry::make('sharer_count')->label('Distinct sharers')->numeric(),
                        TextEntry::make('participant_count')->label('Distinct participants')->numeric(),
                        TextEntry::make('published_at')->label('Published at')->dateTime(),
                        TextEntry::make('published_by_role')->label('Published by role')->badge(),
                    ]),
                ]),
            Section::make('Cells')
                ->description('Coarse threshold-gated proven-evidence-only aggregates. Suppressed cuts publish as null so hidden sides cannot be differenced out.')
                ->schema([
                    TextEntry::make('cells.total_outcomes')->label('Total proven outcomes')->numeric(),
                    TextEntry::make('cells.evidence_basis')->label('Evidence basis')->badge(),
                    TextEntry::make('cells.by_channel')
                        ->label('By channel')
                        ->state(fn (CommunityRelease $record): string => self::cutLabel($record, 'by_channel'))
                        ->placeholder('Suppressed'),
                    TextEntry::make('cells.by_outcome')
                        ->label('By outcome')
                        ->state(fn (CommunityRelease $record): string => self::cutLabel($record, 'by_outcome'))
                        ->placeholder('Suppressed'),
                    TextEntry::make('cells.suppressed')
                        ->label('Suppressed cuts')
                        ->state(fn (CommunityRelease $record): string => implode(', ', (array) (($record->cells ?? [])['suppressed'] ?? [])) ?: 'None'),
                ]),
        ]);
    }

    private static function cutLabel(CommunityRelease $record, string $cut): string
    {
        $cells = (array) (($record->cells ?? [])[$cut] ?? []);

        if ($cells === []) {
            return 'Suppressed';
        }

        $parts = [];

        foreach ($cells as $name => $total) {
            $parts[] = (string) $name.': '.(int) $total;
        }

        return implode(' · ', $parts);
    }
}
