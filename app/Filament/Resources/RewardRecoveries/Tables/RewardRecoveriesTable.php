<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardRecoveries\Tables;

use App\Actions\Rewards\SettleRewardRecoveryAction;
use App\Actions\Rewards\WriteOffRewardRecoveryAction;
use App\Enums\Rewards\RewardRecoveryStatus;
use App\Models\RewardRecovery;
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

class RewardRecoveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('recovery_key')->label('Case')->searchable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('value')
                    ->state(fn (RewardRecovery $record): string => $record->amount_minor !== null
                        ? ((string) $record->amount_minor.' '.(string) $record->currency)
                        : ($record->points !== null ? ((string) $record->points.' '.(string) $record->unit) : 'benefit unit')),
                TextColumn::make('opened_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'settled' => 'Settled', 'written_off' => 'Written off']),
                SelectFilter::make('kind')->options(['receivable' => 'Receivable', 'adjustment' => 'Adjustment', 'dispute' => 'Dispute']),
            ])
            ->recordActions([
                Action::make('settle')
                    ->label('Settle')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Settle recovery')
                    ->modalDescription('Settlement requires proof that value actually returned. Settled cash replenishes the program budget.')
                    ->schema([TextInput::make('recovery_proof')->label('Recovery proof reference')->required()->maxLength(191)])
                    ->visible(fn (RewardRecovery $record): bool => $record->status === RewardRecoveryStatus::Open)
                    ->action(function (RewardRecovery $record, array $data, SettleRewardRecoveryAction $settle): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $settle->handle($record, ['recovery_proof' => (string) $data['recovery_proof']], 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole());
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['recovery_proof' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Recovery settled')->success()->send();
                    }),
                Action::make('write_off')
                    ->label('Write off')
                    ->icon(Heroicon::OutlinedDocumentMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Write off recovery')
                    ->modalDescription('Closes the case without pretending value returned. No budget movement.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardRecovery $record): bool => $record->status === RewardRecoveryStatus::Open)
                    ->action(function (RewardRecovery $record, array $data, WriteOffRewardRecoveryAction $writeOff): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $writeOff->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Recovery written off')->success()->send();
                    }),
            ]);
    }
}
