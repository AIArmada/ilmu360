<?php

namespace App\Filament\Resources\MembershipClaims\Tables;

use App\Actions\Membership\ApproveMembershipClaimAction;
use App\Actions\Membership\RejectMembershipClaimAction;
use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Models\MembershipClaim;
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
                    ->state(fn (MembershipClaim $record): string => MembershipClaimPresenter::subjectTitle($record))
                    ->url(fn (MembershipClaim $record): string => MembershipClaimResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipClaimPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => MembershipClaimPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('granted_role_slug')
                    ->label('Granted Role')
                    ->state(fn (MembershipClaim $record): string => MembershipClaimPresenter::roleLabel($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('claimant.email')
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
                        MembershipClaimStatus::Pending->value => MembershipClaimPresenter::labelForStatus(MembershipClaimStatus::Pending),
                        MembershipClaimStatus::Approved->value => MembershipClaimPresenter::labelForStatus(MembershipClaimStatus::Approved),
                        MembershipClaimStatus::Rejected->value => MembershipClaimPresenter::labelForStatus(MembershipClaimStatus::Rejected),
                        MembershipClaimStatus::Cancelled->value => MembershipClaimPresenter::labelForStatus(MembershipClaimStatus::Cancelled),
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
                    ->schema(fn (MembershipClaim $record): array => [
                        Select::make('granted_role_slug')
                            ->label('Granted Role')
                            ->options(MembershipClaimPresenter::approvalRoleOptions($record))
                            ->required(),
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (MembershipClaim $record, array $data, ApproveMembershipClaimAction $approveMembershipClaimAction): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $approveMembershipClaimAction->handle(
                            $record,
                            $user,
                            (string) $data['granted_role_slug'],
                            filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                        );

                        Notification::make()
                            ->title('Membership claim approved')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (MembershipClaim $record): bool => $record->status === MembershipClaimStatus::Pending),
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
                    ->action(function (MembershipClaim $record, array $data, RejectMembershipClaimAction $rejectMembershipClaimAction): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $rejectMembershipClaimAction->handle(
                            $record,
                            $user,
                            filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                        );

                        Notification::make()
                            ->title('Membership claim rejected')
                            ->danger()
                            ->send();
                    })
                    ->visible(fn (MembershipClaim $record): bool => $record->status === MembershipClaimStatus::Pending),
                Action::make('open_subject')
                    ->label('Open Record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MembershipClaim $record): ?string => MembershipClaimPresenter::subjectAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (MembershipClaim $record): bool => filled(MembershipClaimPresenter::subjectAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (MembershipClaim $record): string => MembershipClaimResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
