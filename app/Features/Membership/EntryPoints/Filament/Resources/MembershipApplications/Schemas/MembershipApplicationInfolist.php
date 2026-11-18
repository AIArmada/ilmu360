<?php

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Schemas;

use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationAdminPresenter;
use App\Features\Membership\Models\MembershipApplication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationAdminPresenter::labelForSubject($state)),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationAdminPresenter::labelForStatus($state))
                                                    ->color(fn (mixed $state): string => MembershipApplicationAdminPresenter::statusColor($state)),
                                                TextEntry::make('subject_summary')
                                                    ->label('Record')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::subjectTitle($record))
                                                    ->url(fn (MembershipApplication $record): ?string => MembershipApplicationAdminPresenter::subjectAdminUrl($record))
                                                    ->openUrlInNewTab(),
                                                TextEntry::make('granted_role')
                                                    ->label('Granted Role')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::roleLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('applied_role')
                                                    ->label('Applied Role')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::appliedRoleLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('relationship')
                                                    ->label('Relationship')
                                                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::relationshipLabel($record))
                                                    ->placeholder('-'),
                                                TextEntry::make('applicant.name')
                                                    ->label('Applicant')
                                                    ->placeholder('-')
                                                    ->url(fn (MembershipApplication $record): ?string => $record->applicant
                                                        ? UserResource::getUrl('view', ['record' => $record->applicant])
                                                        : null)
                                                    ->openUrlInNewTab(),
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
                                        TextEntry::make('evidence_preview')
                                            ->label('Evidence Preview')
                                            ->html()
                                            ->state(fn (MembershipApplication $record): HtmlString => MembershipApplicationAdminPresenter::evidencePreviewHtml($record, 16)),
                                        TextEntry::make('evidence_links')
                                            ->label('Files')
                                            ->state(fn (MembershipApplication $record) => MembershipApplicationAdminPresenter::evidenceLinks($record))
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
