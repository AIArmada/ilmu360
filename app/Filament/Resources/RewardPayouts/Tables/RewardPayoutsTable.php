<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayouts\Tables;

use App\Actions\Rewards\ApproveRewardPayoutAction;
use App\Actions\Rewards\CancelRewardPayoutAction;
use App\Actions\Rewards\ConfirmRewardPayoutAction;
use App\Actions\Rewards\ReconcileRewardPayoutAction;
use App\Actions\Rewards\SubmitRewardPayoutAction;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Models\RewardAward;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RewardPayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('first_reference')->label('Reference')->searchable(),
                TextColumn::make('recipient')
                    ->label('Recipient')
                    ->state(function (RewardPayoutRequest $record): string {
                        $award = RewardAward::query()->whereKey((string) $record->award_id)->first();

                        return $award instanceof RewardAward
                            ? RewardAdminPresenter::recipientLabel((string) $award->recipient_type, (string) $award->recipient_id)
                            : '—';
                    }),
                TextColumn::make('amount_minor')->label('Amount (minor)')->numeric(),
                TextColumn::make('currency')->badge(),
                TextColumn::make('processor')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('requested_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'requested' => 'Requested',
                    'approved' => 'Approved',
                    'submitted' => 'Submitted',
                    'confirmed' => 'Confirmed',
                    'failed' => 'Failed',
                    'unknown' => 'Unknown',
                    'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Independently approve payout')
                    ->modalDescription('The approver must differ from the recipient and the requester.')
                    ->schema([Textarea::make('reason')->label('Approval reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardPayoutRequest $record): bool => $record->status === RewardPayoutRequestStatus::Requested)
                    ->action(function (RewardPayoutRequest $record, array $data, ApproveRewardPayoutAction $approve): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $approve->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Payout approved')->success()->send();
                    }),
                Action::make('submit')
                    ->label('Submit')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Submit through the payout rail')
                    ->modalDescription('Posts the fixed-amount backing and drives the configured processor. Provider calls run outside transactions via the durable operation.')
                    ->visible(fn (RewardPayoutRequest $record): bool => in_array($record->status, [RewardPayoutRequestStatus::Approved, RewardPayoutRequestStatus::Unknown], true))
                    ->action(function (RewardPayoutRequest $record, SubmitRewardPayoutAction $submit): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $submit->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title('Submission refused')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Payout submitted')->success()->send();
                    }),
                Action::make('confirm')
                    ->label('Confirm bank transfer')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->color('success')
                    ->modalHeading('Independently confirm the bank transfer')
                    ->modalDescription('Manual confirmation with bank evidence. The confirmer must differ from the recipient and the requester.')
                    ->schema([TextInput::make('bank_reference')->label('Bank reference')->required()->maxLength(191)])
                    ->visible(fn (RewardPayoutRequest $record): bool => $record->status === RewardPayoutRequestStatus::Submitted)
                    ->action(function (RewardPayoutRequest $record, array $data, ConfirmRewardPayoutAction $confirm): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $confirm->handle($record, ['bank_reference' => (string) $data['bank_reference']], 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['bank_reference' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Payout confirmed')->success()->send();
                    }),
                Action::make('reconcile')
                    ->label('Reconcile')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reconcile unknown payout')
                    ->modalDescription('Re-queries the provider. Late completion is accepted; confirmed failure releases the reservation; anything else stays unknown.')
                    ->visible(fn (RewardPayoutRequest $record): bool => $record->status === RewardPayoutRequestStatus::Unknown)
                    ->action(function (RewardPayoutRequest $record, ReconcileRewardPayoutAction $reconcile): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $reconcile->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title('Reconciliation refused')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Payout reconciled')->success()->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel payout')
                    ->modalDescription('Unknown payouts cancel only when the provider confirms. An unconfirmed cancel keeps the reservation — reconcile instead.')
                    ->schema([Textarea::make('reason')->label('Cancellation reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardPayoutRequest $record): bool => in_array($record->status, [RewardPayoutRequestStatus::Requested, RewardPayoutRequestStatus::Approved, RewardPayoutRequestStatus::Unknown], true))
                    ->action(function (RewardPayoutRequest $record, array $data, CancelRewardPayoutAction $cancel): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $cancel->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Payout cancelled')->success()->send();
                    }),
            ]);
    }
}
