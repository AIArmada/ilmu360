<?php

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Tables;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationAdminPresenter;
use App\Features\Membership\Models\MembershipApplication;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

class MembershipApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('evidence')
                    ->label('Evidence')
                    ->html()
                    ->state(fn (MembershipApplication $record): HtmlString => MembershipApplicationAdminPresenter::evidencePreviewHtml($record, 12)),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationAdminPresenter::labelForSubject($state))
                    ->sortable(),
                TextColumn::make('subject_summary')
                    ->label('Record')
                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::subjectTitle($record))
                    ->url(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => MembershipApplicationAdminPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => MembershipApplicationAdminPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('granted_role')
                    ->label('Granted Role')
                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::roleLabel($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_role')
                    ->label('Applied Role')
                    ->state(fn (MembershipApplication $record): string => MembershipApplicationAdminPresenter::appliedRoleLabel($record))
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
                        ApplicationStatus::Pending->value => MembershipApplicationAdminPresenter::labelForStatus(ApplicationStatus::Pending),
                        ApplicationStatus::Approved->value => MembershipApplicationAdminPresenter::labelForStatus(ApplicationStatus::Approved),
                        ApplicationStatus::Rejected->value => MembershipApplicationAdminPresenter::labelForStatus(ApplicationStatus::Rejected),
                        ApplicationStatus::Cancelled->value => MembershipApplicationAdminPresenter::labelForStatus(ApplicationStatus::Cancelled),
                    ]),
                SelectFilter::make('subject_type')
                    ->options([
                        MemberSubjectType::Institution->value => MembershipApplicationAdminPresenter::labelForSubject(MemberSubjectType::Institution),
                        MemberSubjectType::Person->value => MembershipApplicationAdminPresenter::labelForSubject(MemberSubjectType::Person),
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
                            ->options(MembershipApplicationAdminPresenter::approvalRoleOptions())
                            ->default($record->meta['applied_role'] ?? null)
                            ->required(),
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (MembershipApplication $record, array $data, MembershipApplications $membershipApplications): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $membershipApplications->approve(
                            (string) $record->getKey(),
                            (string) $user->getKey(),
                            (string) $data['granted_role'],
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
                    ->action(function (MembershipApplication $record, array $data, MembershipApplications $membershipApplications): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $membershipApplications->reject(
                            (string) $record->getKey(),
                            (string) $user->getKey(),
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
                    ->url(fn (MembershipApplication $record): ?string => MembershipApplicationAdminPresenter::subjectAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (MembershipApplication $record): bool => filled(MembershipApplicationAdminPresenter::subjectAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('discard')
                        ->label('Discard')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Discard Membership Applications')
                        ->modalDescription('Remove the selected applications and their evidence files. This cannot be undone.')
                        ->authorizeIndividualRecords('delete')
                        ->action(function (BulkAction $action, Collection $records, MembershipApplications $membershipApplications): void {
                            $successCount = 0;
                            $isFirstException = true;

                            foreach ($records as $record) {
                                if (! $record instanceof MembershipApplication) {
                                    $action->reportBulkProcessingFailure();

                                    continue;
                                }

                                try {
                                    $membershipApplications->discard((string) $record->getKey());
                                    $successCount++;
                                } catch (Throwable $exception) {
                                    $action->reportBulkProcessingFailure();

                                    if ($isFirstException) {
                                        report($exception);
                                        $isFirstException = false;
                                    }
                                }
                            }

                            $action->reportBulkProcessingSuccessfulRecordsCount($successCount);
                        })
                        ->successNotificationTitle('Membership applications discarded')
                        ->failureNotificationTitle(fn (int $successCount): string => $successCount > 0
                            ? 'Some membership applications could not be discarded'
                            : 'No membership applications were discarded')
                        ->missingBulkAuthorizationFailureNotificationMessage('You are not authorized to discard one or more selected applications.')
                        ->missingBulkProcessingFailureNotificationMessage('One or more selected applications could not be discarded.')
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
