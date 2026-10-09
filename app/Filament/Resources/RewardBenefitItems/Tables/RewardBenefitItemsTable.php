<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitItems\Tables;

use App\Actions\Rewards\RetireRewardBenefitItemAction;
use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Models\RewardBenefitItem;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use App\Support\Rewards\RewardBenefitStockLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RewardBenefitItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('benefit_key')->label('Key')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('program_title')
                    ->label('Program')
                    ->state(fn (RewardBenefitItem $record): string => (string) (RewardProgram::query()->whereKey((string) $record->program_id)->value('title') ?? $record->program_id)),
                TextColumn::make('cost')->state(fn (RewardBenefitItem $record): string => $record->direct_entitlement ? 'direct' : ((string) $record->cost_points.' '.(string) $record->cost_unit)),
                TextColumn::make('stock')
                    ->state(function (RewardBenefitItem $record): string {
                        $balances = RewardBenefitStockLedger::balances($record);

                        return $balances['available'].' / '.$balances['capacity'];
                    }),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'retired' => 'Retired']),
            ])
            ->recordActions([
                Action::make('retire')
                    ->label('Retire')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retire catalog item')
                    ->modalDescription('Outstanding grants keep reconciling against frozen terms; only new redemptions stop.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3)])
                    ->visible(fn (RewardBenefitItem $record): bool => $record->status === RewardBenefitItemStatus::Active)
                    ->action(function (RewardBenefitItem $record, array $data, RetireRewardBenefitItemAction $retire): void {
                        $staff = RewardAdminPresenter::staffActor();
                        abort_unless($staff !== null, 403);

                        try {
                            $retire->handle($record, 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
                        }

                        Notification::make()->title('Catalog item retired')->success()->send();
                    }),
            ]);
    }
}
