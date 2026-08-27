<?php

namespace App\Filament\Resources\MembershipApplications\Schemas;

use App\Models\MembershipApplication;
use App\Support\Membership\MembershipApplicationPresenter;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class MembershipApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('MembershipApplicationViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Overview')
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Section::make('Application')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('subject_type')
                                                    ->label('Subject')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationPresenter::labelForSubject($state)),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationPresenter::labelForStatus($state))
                                                    ->color(fn (mixed $state): string => MembershipApplicationPresenter::statusColor($state)),
                                                TextEntry::make('subject_summary')
                                                    ->label('Record')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::subjectTitle($record))
                                                    ->url(fn (MembershipApplication $record): ?string => MembershipApplicationPresenter::subjectAdminUrl($record))
                                                    ->openUrlInNewTab(),
                                                TextEntry::make('granted_role')
                                                    ->label('Granted Role')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::roleLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('applied_role')
                                                    ->label('Applied Role')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::appliedRoleLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('relationship')
                                                    ->label('Relationship')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::relationshipLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('applicant.name')
                                                    ->label('Applicant')
                                                    ->placeholder('-'),
                                                TextEntry::make('applicant.email')
                                                    ->label('Applicant Email')
                                                    ->placeholder('-'),
                                                TextEntry::make('applicant.phone')
                                                    ->label('Applicant Phone')
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
                                        TextEntry::make('applicant_note')
                                            ->label('Applicant Note')
                                            ->state(fn (MembershipApplication $record): string => trim((string) data_get($record->meta, 'notes', '')))
                                            ->placeholder('-')
                                            ->columnSpanFull(),
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
                                            ->state(fn (MembershipApplication $record) => MembershipApplicationPresenter::evidenceLinks($record))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
