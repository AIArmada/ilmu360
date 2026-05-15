<?php

namespace App\Filament\Resources\ContributionRequests\Schemas;

use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Models\ContributionRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ContributionRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('ContributionRequestViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Overview')
                            ->icon('heroicon-m-clipboard-document-list')
                            ->schema([
                                Section::make('Request')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('type')
                                                    ->label('Request Type')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForType($state)),
                                                TextEntry::make('subject_type')
                                                    ->label('Subject')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForSubject($state)),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForStatus($state))
                                                    ->color(fn (mixed $state): string => ContributionRequestPresenter::statusColor($state)),
                                                TextEntry::make('entity_summary')
                                                    ->label('Record')
                                                    ->state(fn (ContributionRequest $record): string => ContributionRequestPresenter::entityTitle($record))
                                                    ->url(fn (ContributionRequest $record): ?string => ContributionRequestPresenter::entityAdminUrl($record))
                                                    ->openUrlInNewTab(),
                                                TextEntry::make('proposer.name')
                                                    ->label('Proposer')
                                                    ->placeholder('-'),
                                                TextEntry::make('proposer.email')
                                                    ->label('Proposer Email')
                                                    ->placeholder('-'),
                                                TextEntry::make('reviewer.name')
                                                    ->label('Reviewer')
                                                    ->placeholder('-'),
                                                TextEntry::make('reviewer.email')
                                                    ->label('Reviewer Email')
                                                    ->placeholder('-'),
                                                TextEntry::make('reason_code')
                                                    ->label('Reason Code')
                                                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::headline($state) : '-')
                                                    ->placeholder('-'),
                                                TextEntry::make('created_at')
                                                    ->label('Submitted At')
                                                    ->dateTime(),
                                                TextEntry::make('reviewed_at')
                                                    ->label('Reviewed At')
                                                    ->dateTime()
                                                    ->placeholder('-'),
                                                TextEntry::make('cancelled_at')
                                                    ->label('Cancelled At')
                                                    ->dateTime()
                                                    ->placeholder('-'),
                                            ]),
                                    ]),
                                Section::make('Notes')
                                    ->schema([
                                        TextEntry::make('proposer_note')
                                            ->label('Proposer Note')
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                        TextEntry::make('reviewer_note')
                                            ->label('Reviewer Note')
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Payload')
                            ->icon('heroicon-m-code-bracket')
                            ->schema([
                                Section::make('Payload Summary')
                                    ->schema([
                                        TextEntry::make('changed_fields')
                                            ->label('Changed Fields')
                                            ->state(fn (ContributionRequest $record): string => ContributionRequestPresenter::changedFields($record))
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Original Data')
                                    ->schema([
                                        TextEntry::make('original_data_preview')
                                            ->label('')
                                            ->state(fn (ContributionRequest $record) => ContributionRequestPresenter::prettyJson($record->original_data))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Proposed Data')
                                    ->schema([
                                        TextEntry::make('proposed_data_preview')
                                            ->label('')
                                            ->state(fn (ContributionRequest $record) => ContributionRequestPresenter::prettyJson($record->proposed_data))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
