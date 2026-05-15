<?php

namespace App\Filament\Resources\MembershipClaims\Schemas;

use App\Models\MembershipClaim;
use App\Support\Membership\MembershipClaimPresenter;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class MembershipClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('MembershipClaimViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Overview')
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Section::make('Claim')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('subject_type')
                                                    ->label('Subject')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipClaimPresenter::labelForSubject($state)),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipClaimPresenter::labelForStatus($state))
                                                    ->color(fn (mixed $state): string => MembershipClaimPresenter::statusColor($state)),
                                                TextEntry::make('subject_summary')
                                                    ->label('Record')
                                                    ->state(fn (MembershipClaim $record): string => MembershipClaimPresenter::subjectTitle($record))
                                                    ->url(fn (MembershipClaim $record): ?string => MembershipClaimPresenter::subjectAdminUrl($record))
                                                    ->openUrlInNewTab(),
                                                TextEntry::make('granted_role_slug')
                                                    ->label('Granted Role')
                                                    ->state(fn (MembershipClaim $record): string => MembershipClaimPresenter::roleLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('claimant.name')
                                                    ->label('Claimant')
                                                    ->placeholder('-'),
                                                TextEntry::make('claimant.email')
                                                    ->label('Claimant Email')
                                                    ->placeholder('-'),
                                                TextEntry::make('reviewer.name')
                                                    ->label('Reviewer')
                                                    ->placeholder('-'),
                                                TextEntry::make('reviewer.email')
                                                    ->label('Reviewer Email')
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
                                        TextEntry::make('justification')
                                            ->label('Justification')
                                            ->columnSpanFull(),
                                        TextEntry::make('reviewer_note')
                                            ->label('Reviewer Note')
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Evidence')
                            ->icon('heroicon-m-paper-clip')
                            ->schema([
                                Section::make('Evidence Files')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('evidence')
                                            ->label('Evidence Preview')
                                            ->collection('evidence')
                                            ->conversion('thumb')
                                            ->stacked()
                                            ->limit(8)
                                            ->limitedRemainingText(),
                                        TextEntry::make('evidence_links')
                                            ->label('Files')
                                            ->state(fn (MembershipClaim $record) => MembershipClaimPresenter::evidenceLinks($record))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
