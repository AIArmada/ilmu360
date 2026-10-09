<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPayees\Pages;

use App\Actions\Rewards\VerifyRewardPayeeAction;
use App\Filament\Resources\RewardPayees\RewardPayeeResource;
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

class ListRewardPayees extends ListRecords
{
    protected static string $resource = RewardPayeeResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Register + verify payee')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->modalHeading('Register and verify a payee')
                ->modalDescription('Verification is independent: the verifier must not be the recipient. Only a masked display value is stored.')
                ->schema([
                    TextInput::make('recipient_id')->label('Recipient user id (UUID)')->required()->uuid(),
                    Select::make('method')->options([
                        'bank_transfer' => 'Bank transfer',
                        'check' => 'Check',
                        'wire' => 'Wire',
                        'paypal' => 'PayPal',
                        'stripe_connect' => 'Stripe Connect',
                    ])->required(),
                    TextInput::make('account_ref')->label('Operator account handle')->required()->maxLength(191),
                    TextInput::make('account_display')->label('Masked display (e.g. Bank ••1234)')->required()->maxLength(191),
                    Textarea::make('evidence_note')->label('Verification evidence')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data, VerifyRewardPayeeAction $verify): void {
                    $staff = RewardAdminPresenter::staffActor();
                    abort_unless($staff !== null, 403);

                    try {
                        $verify->handle(
                            'user', (string) $data['recipient_id'], (string) $data['method'],
                            (string) $data['account_ref'], (string) $data['account_display'],
                            'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(),
                            ['note' => (string) ($data['evidence_note'] ?? '')],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['recipient_id' => $exception->getMessage()]);
                    }

                    Notification::make()->title('Payee verified')->success()->send();
                }),
        ];
    }
}
