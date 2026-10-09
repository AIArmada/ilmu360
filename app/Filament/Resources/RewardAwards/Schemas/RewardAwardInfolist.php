<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAwards\Schemas;

use App\Models\RewardAward;
use App\Models\RewardAwardEntry;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RewardAwardInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Award')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('program_title')
                            ->label('Program')
                            ->state(fn (RewardAward $record): string => (string) (RewardProgram::query()->whereKey((string) $record->program_id)->value('title') ?? $record->family_key)),
                        TextEntry::make('recipient')
                            ->label('Recipient')
                            ->state(fn (RewardAward $record): string => RewardAdminPresenter::recipientLabel((string) $record->recipient_type, (string) $record->recipient_id)),
                        TextEntry::make('beneficiary_role')->label('Role'),
                        TextEntry::make('component')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::componentOptions()[(string) $state->value] ?? (string) $state->value),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::statusLabel($state))
                            ->color(fn (mixed $state): string => RewardAdminPresenter::statusColor($state)),
                        TextEntry::make('source_business_key')
                            ->label('Source key')
                            ->copyable(),
                    ]),
                ]),
            Section::make('Decision trail')
                ->schema([
                    RepeatableEntry::make('entries')
                        ->label('')
                        ->state(fn (RewardAward $record): array => RewardAwardEntry::query()
                            ->where('award_id', $record->getKey())
                            ->orderBy('seq')
                            ->get()
                            ->map(fn (RewardAwardEntry $entry): array => [
                                'transition' => ((string) $entry->from_status) === '' ? 'evaluated' : ((string) $entry->from_status.' → '.(string) $entry->to_status),
                                'reason' => (string) $entry->reason,
                                'decided_by' => (string) $entry->actor_role,
                                'decided_at' => (string) $entry->decided_at,
                            ])->all())
                        ->schema([
                            TextEntry::make('transition')->label('Transition'),
                            TextEntry::make('reason')->label('Reason')->columnSpan(2),
                            TextEntry::make('decided_by')->label('Decided by'),
                            TextEntry::make('decided_at')->label('Decided at'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
