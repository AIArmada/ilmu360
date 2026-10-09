<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayees\Tables;

use App\Actions\Rewards\RejectRewardPayeeAction;
use App\Actions\Rewards\VerifyRewardPayeeAction;
use App\Enums\Rewards\RewardPayeeStatus;
use App\Models\RewardPayee;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RewardPayeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('recipient')
                    ->label('Recipient')
                    ->state(fn (RewardPayee $record): string => RewardAdminPresenter::recipientLabel((string) $record->recipient_type, (string) $record->recipient_id))
                    ->searchable(query: fn ($query, string $search) => $query->where('recipient_id', $search)),
                TextColumn::make('method')->badge(),
                TextColumn::make('account_display')->label('Account'),
                TextColumn::make('status')->badge(),
                TextColumn::make('verified_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                    'superseded' => 'Superseded',
                ]),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify payee destination')
                    ->modalDescription('Activates this destination and supersedes prior pending/verified rows for the same recipient and method. You cannot verify your own destination.')
                    ->visible(fn (RewardPayee $record): bool => $record->status === RewardPayeeStatus::Pending)
                    ->action(function (RewardPayee $record, VerifyRewardPayeeAction $verify): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $verify->handle(
                                (string) $record->recipient_type,
                                (string) $record->recipient_id,
                                (string) $record->method,
                                (string) $record->account_ref,
                                (string) $record->account_display,
                                'user',
                                (string) $staff->getKey(),
                                RewardAdminPresenter::staffRole(),
                                ['verified_from_registration_id' => (string) $record->getKey()],
                            );
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['method' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Payee verified')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject payee registration')
                    ->modalDescription('Closes this pending registration with a reason. The recipient keeps any live verified destination.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardPayee $record): bool => $record->status === RewardPayeeStatus::Pending)
                    ->action(function (RewardPayee $record, array $data, RejectRewardPayeeAction $reject): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $reject->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Payee rejected')->success()->send();
                    }),
            ]);
    }
}
