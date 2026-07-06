<?php

namespace App\Filament\Resources\MembershipClaims\Tables;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Membership\MembershipClaimPresenter;
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

class MembershipClaimsTable
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
                    ->formatStateUsing(fn (mixed $state): string => MembershipClaimPresenter::labelForSubject($state))
                    ->sortable(),
                TextColumn::make('subject_summary')
                    ->label('Record')
                    ->state(fn (MembershipApplication $record): string => MembershipClaimPresenter::subjectTitle($record))
                    ->url(fn (MembershipApplication $record): string => MembershipClaimResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipClaimPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => MembershipClaimPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('granted_role')
                    ->label('Granted Role')
                    ->state(fn (MembershipApplication $record): string => MembershipClaimPresenter::roleLabel($record))
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
                        ApplicationStatus::Pending->value => MembershipClaimPresenter::labelForStatus(ApplicationStatus::Pending),
                        ApplicationStatus::Approved->value => MembershipClaimPresenter::labelForStatus(ApplicationStatus::Approved),
                        ApplicationStatus::Rejected->value => MembershipClaimPresenter::labelForStatus(ApplicationStatus::Rejected),
                        ApplicationStatus::Cancelled->value => MembershipClaimPresenter::labelForStatus(ApplicationStatus::Cancelled),
                    ]),
                SelectFilter::make('subject_type')
                    ->options([
                        MemberSubjectType::Institution->value => MembershipClaimPresenter::labelForSubject(MemberSubjectType::Institution),
                        MemberSubjectType::Speaker->value => MembershipClaimPresenter::labelForSubject(MemberSubjectType::Speaker),
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Membership Claim')
                    ->modalDescription('Approve this claim and choose the role to grant.')
                    ->schema(fn (MembershipApplication $record): array => [
                        Select::make('granted_role')
                            ->label('Granted Role')
                            ->options(MembershipClaimPresenter::approvalRoleOptions($record))
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
                            ->title('Membership claim approved')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject Membership Claim')
                    ->modalDescription('Reject this claim and optionally leave guidance for the claimant.')
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
                            ->title('Membership claim rejected')
                            ->danger()
                            ->send();
                    })
                    ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending),
                Action::make('open_subject')
                    ->label('Open Record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MembershipApplication $record): ?string => MembershipClaimPresenter::subjectAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (MembershipApplication $record): bool => filled(MembershipClaimPresenter::subjectAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (MembershipApplication $record): string => MembershipClaimResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
