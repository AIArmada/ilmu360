<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFraud\Tables;

use App\Actions\Rewards\DismissRewardFraudAction;
use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Models\RewardAward;
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

class RewardFraudTable
{
    /**
     * @var array<string, string>
     */
    private static array $fraudCache = [];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('decided_at', 'desc')
            ->columns([
                TextColumn::make('component')->badge(),
                TextColumn::make('recipient')
                    ->label('Recipient')
                    ->state(fn (RewardAward $record): string => RewardAdminPresenter::recipientLabel((string) $record->recipient_type, (string) $record->recipient_id)),
                TextColumn::make('status')->badge(),
                TextColumn::make('fraud_status')
                    ->label('Fraud')
                    ->badge()
                    ->state(fn (RewardAward $record): string => self::fraudStatusFor($record)),
                TextColumn::make('source_business_key')->label('Source key')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decided_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('component')->options([
                    'recognition' => 'Recognition',
                    'points' => 'Points',
                    'badge' => 'Badge',
                    'cash' => 'Cash',
                    'benefit' => 'Benefit',
                ]),
            ])
            ->recordActions([
                Action::make('dismiss')
                    ->label('Dismiss fraud')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Dismiss fraud suspicion')
                    ->modalDescription('Dismissal records reason/actor/time evidence. It is NOT award approval and clears no financial hold by itself.')
                    ->schema([Textarea::make('reason')->label('Dismissal reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardAward $record): bool => self::fraudStatusFor($record) !== RewardSourceFraudStatus::Clear->value
                        && auth()->user()?->can('reward-fraud-steward') === true)
                    ->action(function (RewardAward $record, array $data, DismissRewardFraudAction $dismiss): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $dismiss->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Fraud suspicion dismissed')->success()->send();
                    }),
            ]);
    }

    private static function fraudStatusFor(RewardAward $record): string
    {
        $key = (string) $record->source_business_key;

        if (! array_key_exists($key, self::$fraudCache)) {
            $fact = app(RewardSourceFactReader::class)->read($key);

            self::$fraudCache[$key] = $fact?->fraudStatus->value ?? 'unknown';
        }

        return self::$fraudCache[$key];
    }
}
