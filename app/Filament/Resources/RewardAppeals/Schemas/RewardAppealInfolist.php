<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAppeals\Schemas;

use App\Models\RewardAppeal;
use App\Models\RewardAppealDecision;
use App\Models\RewardAward;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RewardAppealInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Appeal')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('member')
                            ->label('Member')
                            ->state(function (RewardAppeal $record): string {
                                $award = RewardAward::query()->whereKey((string) $record->award_id)->first();

                                return $award instanceof RewardAward
                                    ? RewardAdminPresenter::recipientLabel((string) $award->recipient_type, (string) $award->recipient_id)
                                    : '-';
                            }),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::statusLabel($state))
                            ->color(fn (mixed $state): string => RewardAdminPresenter::statusColor($state)),
                        TextEntry::make('window_ends_at')->label('Window ends')->dateTime(),
                        TextEntry::make('reason')->columnSpanFull(),
                        TextEntry::make('opened_at')->label('Opened')->dateTime(),
                        TextEntry::make('in_review_at')->label('In review')->dateTime()->placeholder('-'),
                        TextEntry::make('upheld_at')->label('Upheld')->dateTime()->placeholder('-'),
                        TextEntry::make('overturned_at')->label('Overturned')->dateTime()->placeholder('-'),
                        TextEntry::make('closed_at')->label('Closed')->dateTime()->placeholder('-'),
                    ]),
                ]),
            Section::make('Decisions')
                ->schema([
                    RepeatableEntry::make('decisions')
                        ->label('')
                        ->state(fn (RewardAppeal $record): array => RewardAppealDecision::query()
                            ->where('appeal_id', $record->getKey())
                            ->orderBy('seq')
                            ->get()
                            ->map(fn (RewardAppealDecision $decision): array => [
                                'transition' => ((string) $decision->from_status).' → '.((string) $decision->to_status),
                                'reason' => (string) $decision->reason,
                                'decided_by' => (string) $decision->actor_role,
                                'decided_at' => (string) $decision->decided_at,
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
