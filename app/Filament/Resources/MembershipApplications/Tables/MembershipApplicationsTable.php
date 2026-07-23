<?php

namespace App\Filament\Resources\MembershipApplications\Tables;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Membership\MembershipApplicationPresenter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembershipApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                SpatieMediaLibraryImageColumn::make('evidence')
                    ->label('Evidence')
                    ->collection('evidence')
                    ->conversion('thumb')
                    ->square()
                    ->size(52),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationPresenter::labelForSubject($state))
                    ->sortable(),
                TextColumn::make('subject_summary')
                    ->label('Record')
                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::subjectTitle($record))
                    ->url(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => MembershipApplicationPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('granted_role')
                    ->label('Granted Role')
                    ->state(fn (MembershipApplication $record): string => MembershipApplicationPresenter::roleLabel($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applicant.email')
                    ->label('Claimant')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('reviewer.email')
                    ->label('Reviewer')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->since()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ApplicationStatus::Pending->value => MembershipApplicationPresenter::labelForStatus(ApplicationStatus::Pending),
                        ApplicationStatus::Approved->value => MembershipApplicationPresenter::labelForStatus(ApplicationStatus::Approved),
                        ApplicationStatus::Rejected->value => MembershipApplicationPresenter::labelForStatus(ApplicationStatus::Rejected),
                        ApplicationStatus::Cancelled->value => MembershipApplicationPresenter::labelForStatus(ApplicationStatus::Cancelled),
                    ]),
                SelectFilter::make('subject_type')
                    ->options([
                        MemberSubjectType::Institution->value => MembershipApplicationPresenter::labelForSubject(MemberSubjectType::Institution),
                        MemberSubjectType::Person->value => MembershipApplicationPresenter::labelForSubject(MemberSubjectType::Person),
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Membership Application')
                    ->modalDescription('Approve this application and choose the role to grant.')
                    ->schema(fn (MembershipApplication $record): array => [
                        Select::make('granted_role')
                            ->label('Granted Role')
                            ->options(MembershipApplicationPresenter::approvalRoleOptions($record))
                            ->required(),
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (MembershipApplication $record, array $data, ApproveMembershipApplicationAction $approveMembershipApplicationAction): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $approveMembershipApplicationAction->handle(
                            $record,
                            $user,
                            MemberRole::tryFrom((string) $data['granted_role']) ?? MemberRole::Editor,
                            filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                        );
                        Notification::make()
                            ->title('Membership application approved')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject Membership Application')
                    ->modalDescription('Reject this application and optionally leave guidance for the applicant.')
                    ->schema([
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (MembershipApplication $record, array $data, RejectMembershipApplicationAction $rejectMembershipApplicationAction): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $rejectMembershipApplicationAction->handle(
                            $record,
                            $user,
                            filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                        );

                        Notification::make()
                            ->title('Membership application rejected')
                            ->danger()
                            ->send();
                    })
                    ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending),
                Action::make('open_subject')
                    ->label('Open Record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MembershipApplication $record): ?string => MembershipApplicationPresenter::subjectAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (MembershipApplication $record): bool => filled(MembershipApplicationPresenter::subjectAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
