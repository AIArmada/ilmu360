<?php

namespace App\Filament\Resources\ContributionRequests\Tables;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Shared\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Models\ContributionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContributionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForType($state))
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForSubject($state))
                    ->sortable(),
                TextColumn::make('entity_summary')
                    ->label('Record')
                    ->state(fn (ContributionRequest $record): string => ContributionRequestPresenter::entityTitle($record))
                    ->url(fn (ContributionRequest $record): string => ContributionRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ContributionRequestPresenter::labelForStatus($state))
                    ->color(fn (mixed $state): string => ContributionRequestPresenter::statusColor($state))
                    ->sortable(),
                TextColumn::make('proposer.email')
                    ->label('Proposer')
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
                    ->options(ContributionRequestPresenter::statusOptions()),
                SelectFilter::make('type')
                    ->options(ContributionRequestPresenter::typeOptions()),
                SelectFilter::make('subject_type')
                    ->options(ContributionRequestPresenter::subjectOptions()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Contribution Request')
                    ->modalDescription('Approve this request and apply the proposed change.')
                    ->schema([
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (ContributionRequest $record, array $data, ContributionRequestLifecycle $contributionRequestLifecycle): void {
                        $user = auth()->user();

                        abort_unless($user instanceof User, 403);

                        try {
                            $contributionRequestLifecycle->approve(
                                (string) $record->getKey(),
                                (string) $user->getKey(),
                                filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                            );
                        } catch (ContributionRequestAlreadyReviewedException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())->flatten()->first();

                            Notification::make()
                                ->title(is_string($message) && $message !== '' ? $message : __('Could not approve this request.'))
                                ->danger()
                                ->send();

                            return;
                        } catch (LockTimeoutException) {
                            Notification::make()
                                ->title(__('The review service is busy. Please try again in a moment.'))
                                ->danger()
                                ->send();

                            return;
                        } catch (ContributionRequestNotPendingException) {
                            Notification::make()
                                ->title(__('This request is no longer pending review. Please refresh to see its current status.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Contribution request approved')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Pending),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject Contribution Request')
                    ->modalDescription('Reject this request and record the moderation reason.')
                    ->schema([
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options(ContributionRequestPresenter::rejectionReasonOptions())
                            ->required(),
                        Textarea::make('reviewer_note')
                            ->label('Reviewer Note')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (ContributionRequest $record, array $data, ContributionRequestLifecycle $contributionRequestLifecycle): void {
                        $user = auth()->user();

                        abort_unless($user instanceof User, 403);

                        try {
                            $contributionRequestLifecycle->reject(
                                (string) $record->getKey(),
                                (string) $user->getKey(),
                                (string) $data['reason_code'],
                                filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                            );
                        } catch (ContributionRequestNotPendingException) {
                            Notification::make()
                                ->title(__('This request is no longer pending review. Please refresh to see its current status.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Contribution request rejected')
                            ->danger()
                            ->send();
                    })
                    ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Pending),
                Action::make('view_entity')
                    ->label('Open Record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ContributionRequest $record): ?string => ContributionRequestPresenter::entityAdminUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (ContributionRequest $record): bool => filled(ContributionRequestPresenter::entityAdminUrl($record))),
                ViewAction::make(),
            ])
            ->recordUrl(fn (ContributionRequest $record): string => ContributionRequestResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            try {
                                $deleted = app(ContributionRequestLifecycle::class)->delete(
                                    $records->modelKeys(),
                                );
                            } catch (Throwable $exception) {
                                report($exception);

                                $action->reportCompleteBulkProcessingFailure();

                                return;
                            }

                            $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                        }),
                ]),
            ]);
    }
}
