<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardAppeals\Tables;

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RewardAppealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('award_recipient')
                    ->label('Member')
                    ->state(fn (RewardAppeal $record): string => self::recipientLabel($record)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::statusLabel($state))
                    ->color(fn (mixed $state): string => RewardAdminPresenter::statusColor($state)),
                TextColumn::make('reason')
                    ->limit(60),
                TextColumn::make('opened_at')
                    ->since()
                    ->sortable(),
                TextColumn::make('window_ends_at')
                    ->label('Window ends')
                    ->dateTime()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(RewardAdminPresenter::appealStatusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('decide')
                    ->label('Decide')
                    ->icon(Heroicon::OutlinedScale)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Decide appeal')
                    ->modalDescription('Overturning appends a merit override on the award; it never rewrites the original fact or policy.')
                    ->schema(fn (RewardAppeal $record): array => [
                        Select::make('to')
                            ->label('Decision')
                            ->options(self::transitionOptions($record))
                            ->required(),
                        Textarea::make('reason')->label('Decision reason')->required()->maxLength(2000)->rows(3),
                    ])
                    ->visible(fn (RewardAppeal $record): bool => $record->status->allowedTransitions() !== []
                        && RewardAdminPresenter::canDecideAward($record, 'decide'))
                    ->action(function (RewardAppeal $record, array $data, AdvanceRewardAppealAction $advance): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $advance->handle(
                                $record,
                                (string) $data['to'],
                                'user',
                                (string) $staff->getKey(),
                                RewardAdminPresenter::staffRole(),
                                (string) $data['reason'],
                            );
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Appeal decided')->success()->send();
                    }),
            ]);
    }

    private static function recipientLabel(RewardAppeal $record): string
    {
        $award = RewardAward::query()->whereKey((string) $record->award_id)->first();

        if (! $award instanceof RewardAward) {
            return '-';
        }

        return RewardAdminPresenter::recipientLabel((string) $award->recipient_type, (string) $award->recipient_id);
    }

    /**
     * @return array<string, string>
     */
    private static function transitionOptions(RewardAppeal $record): array
    {
        $options = [];

        foreach ($record->status->allowedTransitions() as $to) {
            $options[$to->value] = RewardAdminPresenter::statusLabel($to);
        }

        return $options;
    }
}
