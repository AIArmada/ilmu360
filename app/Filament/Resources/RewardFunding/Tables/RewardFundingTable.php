<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFunding\Tables;

use App\Models\RewardFundingEntry;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardBudgetLedger;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RewardFundingTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('funded_at', 'desc')
            ->columns([
                TextColumn::make('program_title')
                    ->label('Program')
                    ->state(fn (RewardFundingEntry $record): string => (string) (RewardProgram::query()->whereKey((string) $record->program_id)->value('title') ?? $record->program_id)),
                TextColumn::make('currency')->badge(),
                TextColumn::make('amount_minor')->label('Amount (minor)')->numeric(),
                TextColumn::make('source_ref')->label('Source ref')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balances')
                    ->label('Funded / Avail')
                    ->state(function (RewardFundingEntry $record): string {
                        $balances = RewardBudgetLedger::balances((string) $record->program_id, (string) $record->currency);

                        return $balances['funded'].' / '.$balances['available'];
                    }),
                TextColumn::make('funded_at')->dateTime()->sortable(),
            ]);
    }
}
