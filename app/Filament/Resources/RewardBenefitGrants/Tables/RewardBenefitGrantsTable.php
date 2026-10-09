<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitGrants\Tables;

use App\Actions\Rewards\CancelRewardBenefitGrantAction;
use App\Actions\Rewards\ConsumeRewardBenefitAction;
use App\Actions\Rewards\ExpireRewardBenefitGrantAction;
use App\Actions\Rewards\FulfillRewardBenefitAction;
use App\Actions\Rewards\RecordRewardBenefitRecallAction;
use App\Actions\Rewards\RevokeRewardBenefitGrantAction;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
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

class RewardBenefitGrantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('reserved_at', 'desc')
            ->columns([
                TextColumn::make('grant_key')->label('Grant')->searchable(),
                TextColumn::make('item_title')
                    ->label('Benefit')
                    ->state(fn (RewardBenefitGrant $record): string => (string) (RewardBenefitItem::query()->whereKey((string) $record->item_id)->value('title') ?? $record->item_id)),
                TextColumn::make('recipient')
                    ->label('Recipient')
                    ->state(fn (RewardBenefitGrant $record): string => RewardAdminPresenter::recipientLabel((string) $record->recipient_type, (string) $record->recipient_id))
                    ->searchable(query: fn ($query, string $search) => $query->where('recipient_id', $search)),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('status')->badge(),
                TextColumn::make('fulfillment_adapter')->label('Adapter')->badge(),
                TextColumn::make('external_ref')->label('Ref')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'reserved' => 'Reserved',
                    'issued' => 'Issued',
                    'consumed' => 'Consumed',
                    'revoked' => 'Revoked',
                    'expired' => 'Expired',
                ]),
            ])
            ->recordActions([
                Action::make('fulfill')
                    ->label('Fulfill')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Fulfill grant')
                    ->modalDescription('Captures held points, consumes held stock, and issues once. Manual handoffs need a reference; coupons issue from frozen voucher terms.')
                    ->schema([TextInput::make('handoff_reference')->label('Handoff reference (manual only)')->maxLength(191)])
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Reserved)
                    ->action(function (RewardBenefitGrant $record, array $data, FulfillRewardBenefitAction $fulfill): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $fulfill->handle($record, array_filter(['handoff_reference' => (string) ($data['handoff_reference'] ?? '')]), 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['handoff_reference' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Grant fulfilled')->success()->send();
                    }),
                Action::make('consume')
                    ->label('Record use')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('primary')
                    ->modalHeading('Record benefit use')
                    ->modalDescription('Coupons consume only on canonical recipient usage; the usage id auto-resolves when omitted.')
                    ->schema([
                        TextInput::make('use_reference')->label('Use reference (manual)')->maxLength(191),
                        TextInput::make('voucher_usage_id')->label('Voucher usage id (coupon)')->maxLength(191),
                    ])
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Issued)
                    ->action(function (RewardBenefitGrant $record, array $data, ConsumeRewardBenefitAction $consume): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $consume->handle($record, array_filter($data), 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['use_reference' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Use recorded')->success()->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel reserved grant')
                    ->modalDescription('Restores held points and stock exactly once.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Reserved)
                    ->action(function (RewardBenefitGrant $record, array $data, CancelRewardBenefitGrantAction $cancel): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $cancel->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Grant cancelled')->success()->send();
                    }),
                Action::make('record_recall')
                    ->label('Record recall')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('info')
                    ->modalHeading('Record manual recall')
                    ->modalDescription('Proof the handed-over benefit was taken back. Revocation of a manual grant requires this reference.')
                    ->schema([TextInput::make('recall_reference')->label('Recall reference')->required()->maxLength(191)])
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Issued
                        && (string) $record->fulfillment_adapter === 'manual'
                        && mb_trim((string) (($record->evidence ?? [])['recall_reference'] ?? '')) === '')
                    ->action(function (RewardBenefitGrant $record, array $data, RecordRewardBenefitRecallAction $recall): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $recall->handle($record, (string) $data['recall_reference'], 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['recall_reference' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Recall recorded')->success()->send();
                    }),
                Action::make('revoke')
                    ->label('Revoke unused')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke unused grant')
                    ->modalDescription('Revokes only when unused (coupon usage blocks). Manual grants need a recorded recall reference on the grant evidence.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Issued)
                    ->action(function (RewardBenefitGrant $record, array $data, RevokeRewardBenefitGrantAction $revoke): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $revoke->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Grant revoked')->success()->send();
                    }),
                Action::make('expire')
                    ->label('Expire')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Expire reserved grant')
                    ->modalDescription('Only for grants on expired catalog items; restores once and closes as expired.')
                    ->visible(fn (RewardBenefitGrant $record): bool => $record->status === RewardBenefitGrantStatus::Reserved)
                    ->action(function (RewardBenefitGrant $record, ExpireRewardBenefitGrantAction $expire): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $expire->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title('Expiry refused')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Grant expired')->success()->send();
                    }),
            ]);
    }
}
