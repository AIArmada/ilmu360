<?php

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Schemas;

use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Models\ContributionRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
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
                                TextEntry::make('self_review_notice')
                                    ->hiddenLabel()
                                    ->state(__('This request was submitted by you. A different reviewer must review it before it can be approved or rejected.'))
                                    ->icon('heroicon-o-exclamation-triangle')
                                    ->color('warning')
                                    ->visible(fn (ContributionRequest $record): bool => $record->isPending()
                                        && ! (auth()->user()?->can('review', $record) ?? false)),
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
                                                    ->formatStateUsing(fn (?string $state): string => filled($state)
                                                        ? (ContributionRequestPresenter::rejectionReasonOptions()[$state] ?? Str::headline($state))
                                                        : '-')
                                                    ->placeholder('-')
                                                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Rejected),
                                                TextEntry::make('created_at')
                                                    ->label('Submitted At')
                                                    ->dateTime(),
                                                TextEntry::make('reviewed_at')
                                                    ->label('Reviewed At')
                                                    ->dateTime()
                                                    ->placeholder('-')
                                                    ->visible(fn (ContributionRequest $record): bool => $record->reviewed_at !== null),
                                                TextEntry::make('approved_at')
                                                    ->label('Approved At')
                                                    ->dateTime()
                                                    ->placeholder('-')
                                                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Approved),
                                                TextEntry::make('rejected_at')
                                                    ->label('Rejected At')
                                                    ->dateTime()
                                                    ->placeholder('-')
                                                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Rejected),
                                                TextEntry::make('cancelled_at')
                                                    ->label('Cancelled At')
                                                    ->dateTime()
                                                    ->placeholder('-')
                                                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Cancelled),
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
                            ->badge(fn (ContributionRequest $record): int => count(ContributionRequestPresenter::payloadChanges($record)))
                            ->schema([
                                Section::make('Changed Fields')
                                    ->schema([
                                        RepeatableEntry::make('payload_changes')
                                            ->label('')
                                            ->state(fn (ContributionRequest $record): array => ContributionRequestPresenter::payloadChanges($record))
                                            ->placeholder('No payload data.')
                                            ->contained(false)
                                            ->columns(3)
                                            ->schema([
                                                TextEntry::make('field')
                                                    ->label('Field'),
                                                TextEntry::make('original')
                                                    ->label('Original')
                                                    ->placeholder('-'),
                                                TextEntry::make('proposed')
                                                    ->label('Proposed')
                                                    ->placeholder('-'),
                                            ]),
                                    ]),
                                Section::make('Raw Payload (JSON)')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        TextEntry::make('proposed_data')
                                            ->label('')
                                            ->state(fn (ContributionRequest $record): HtmlString => self::rawPayloadHtml($record))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Proposed Media')
                            ->icon('heroicon-m-photo')
                            ->badge(fn (ContributionRequest $record): int => $record->getMedia('pending_media')->count())
                            ->schema([
                                Section::make('Staged Media (pending approval)')
                                    ->schema([
                                        TextEntry::make('pending_media')
                                            ->label('')
                                            ->state(fn (ContributionRequest $record) => ContributionRequestPresenter::pendingMediaHtml($record))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    private static function rawPayloadHtml(ContributionRequest $record): HtmlString
    {
        $payload = $record->proposed_data;

        if ($payload === null || $payload === []) {
            return new HtmlString('<span class="text-gray-400">-</span>');
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new HtmlString(
            '<pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;">'
            .e(is_string($json) ? $json : '-')
            .'</pre>'
        );
    }
}
