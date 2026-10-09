<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardBenefitItems\Pages;

use App\Actions\Rewards\CreateRewardBenefitItemAction;
use App\Filament\Resources\RewardBenefitItems\RewardBenefitItemResource;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ListRewardBenefitItems extends ListRecords
{
    protected static string $resource = RewardBenefitItemResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New catalog item')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('success')
                ->modalHeading('Create benefit catalog item')
                ->modalDescription('Terms freeze at creation. Take either a point cost or direct entitlement.')
                ->schema([
                    Select::make('program_id')->label('Program')->options(fn (): array => RewardProgram::query()->orderBy('title')->pluck('title', 'id')->all())->required()->searchable(),
                    TextInput::make('benefit_key')->label('Key (slug)')->required()->maxLength(64),
                    TextInput::make('title')->required()->maxLength(191),
                    Textarea::make('conditions')->label('Conditions (one per line)')->required()->rows(3),
                    TextInput::make('cost_points')->label('Point cost')->numeric()->minValue(1),
                    TextInput::make('cost_unit')->label('Cost unit')->maxLength(16),
                    Toggle::make('direct_entitlement')->label('Direct entitlement (no points)'),
                    TextInput::make('capacity')->required()->numeric()->minValue(1),
                    DateTimePicker::make('expires_at')->label('Expiry (optional)'),
                    Toggle::make('coupon_enabled')->label('Coupon fulfillment (fixed-value voucher)'),
                    TextInput::make('coupon_value')->label('Coupon value (minor units)')->numeric()->minValue(1),
                    TextInput::make('coupon_currency')->label('Coupon currency')->maxLength(3)->default('MYR'),
                ])
                ->action(function (array $data, CreateRewardBenefitItemAction $create): void {
                    $staff = RewardAdminPresenter::staffActor();
                    abort_unless($staff !== null, 403);

                    $conditions = array_values(array_filter(array_map(
                        static fn (string $line): string => trim($line),
                        explode("\n", (string) $data['conditions']),
                    )));

                    $fulfillmentConfig = null;

                    if ((bool) ($data['coupon_enabled'] ?? false)) {
                        $fulfillmentConfig = ['voucher' => [
                            'type' => 'fixed',
                            'value' => (int) ($data['coupon_value'] ?? 0),
                            'currency' => (string) ($data['coupon_currency'] ?? ''),
                        ]];
                    }

                    try {
                        $create->handle(
                            (string) $data['program_id'], (string) $data['benefit_key'], (string) $data['title'],
                            $conditions,
                            $data['cost_points'] !== null && $data['cost_points'] !== '' ? (int) $data['cost_points'] : null,
                            is_string($data['cost_unit'] ?? null) && $data['cost_unit'] !== '' ? (string) $data['cost_unit'] : null,
                            (bool) ($data['direct_entitlement'] ?? false),
                            (int) $data['capacity'],
                            'user', (string) $staff->getKey(), RewardAdminPresenter::staffRole(),
                            is_string($data['expires_at'] ?? null) && $data['expires_at'] !== '' ? new CarbonImmutable($data['expires_at']) : null,
                            $fulfillmentConfig,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['benefit_key' => $exception->getMessage()]);
                    }

                    Notification::make()->title('Catalog item created')->success()->send();
                }),
        ];
    }
}
