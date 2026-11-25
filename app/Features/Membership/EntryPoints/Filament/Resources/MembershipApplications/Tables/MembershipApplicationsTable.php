<?php

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Tables;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationAdminPresenter;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationModerationActions;
use App\Features\Membership\Models\MembershipApplication;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
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
                TextColumn::make('applicant.name')
                    ->label('Claimant')
                    ->description(function (MembershipApplication $record): ?string {
                        $email = $record->applicant?->getAttribute('email');

                        return is_string($email) && $email !== '' ? $email : null;
                    })
                    ->searchable(['name', 'email'])
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
                MembershipApplicationModerationActions::approveAction(),
                MembershipApplicationModerationActions::rejectAction(),
                MembershipApplicationModerationActions::openSubjectAction(),
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

                            MembershipApplicationResource::flushNavigationBadgeCache();

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
