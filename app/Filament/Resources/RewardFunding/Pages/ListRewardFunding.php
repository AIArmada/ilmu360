<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardFunding\Pages;

use App\Actions\Rewards\RecordRewardFundingAction;
use App\Actions\Rewards\RecordRewardFundingSourceAction;
use App\Filament\Resources\RewardFunding\RewardFundingResource;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ListRewardFunding extends ListRecords
{
    protected static string $resource = RewardFundingResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_source')
                ->label('Record source')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('info')
                ->modalHeading('Record confirmed funding source')
                ->modalDescription('A source is the confirmed treasury receipt programs allocate from. Sources are immutable; allocations can never exceed the source total.')
                ->schema([
                    TextInput::make('source_ref')->label('Source reference')->required()->maxLength(191),
                    TextInput::make('currency')->required()->maxLength(3)->default('MYR'),
                    TextInput::make('amount_minor')->label('Confirmed total (minor units)')->required()->numeric()->minValue(1),
                    Textarea::make('evidence_note')->label('Evidence note')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data, RecordRewardFundingSourceAction $recorder): void {
                    $staff = RewardAdminPresenter::staffActor();
                    abort_unless($staff !== null, 403);

                    try {
                        $recorder->handle(
                            (string) $data['source_ref'], (string) $data['currency'], (int) $data['amount_minor'],
                            'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(),
                            ['note' => (string) ($data['evidence_note'] ?? '')],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['source_ref' => $exception->getMessage()]);
                    }

                    Notification::make()->title('Funding source recorded')->success()->send();
                }),
            Action::make('record')
                ->label('Record funding')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('success')
                ->modalHeading('Record confirmed funding')
                ->modalDescription('Allocate confirmed value from a recorded source to one program. Record the source first; this never moves live money.')
                ->schema([
                    Select::make('program_id')->label('Program')->options(fn (): array => RewardProgram::query()->orderBy('title')->pluck('title', 'id')->all())->required()->searchable(),
                    TextInput::make('currency')->required()->maxLength(3)->default('MYR'),
                    TextInput::make('amount_minor')->label('Amount (minor units)')->required()->numeric()->minValue(1),
                    TextInput::make('source_ref')->label('Source reference')->required()->maxLength(191),
                    Textarea::make('evidence_note')->label('Evidence note')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data, RecordRewardFundingAction $recorder): void {
                    $staff = RewardAdminPresenter::staffActor();
                    abort_unless($staff !== null, 403);

                    try {
                        $recorder->handle(
                            (string) $data['program_id'], (string) $data['currency'], (int) $data['amount_minor'],
                            (string) $data['source_ref'], 'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(),
                            ['note' => (string) ($data['evidence_note'] ?? '')],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['source_ref' => $exception->getMessage()]);
                    }

                    Notification::make()->title('Funding recorded')->success()->send();
                }),
        ];
    }
}
