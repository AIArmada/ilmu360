<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use App\Support\Rewards\RewardPresetBuilders;
use App\Support\Rewards\RewardPublishAssembler;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ViewRewardProgram extends ViewRecord
{
    protected static string $resource = RewardProgramResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->publishVersionAction(),
            $this->transitionStatusAction(),
            $this->previewQualificationAction(),
        ];
    }

    private function programRecord(): RewardProgram
    {
        $record = $this->getRecord();

        abort_unless($record instanceof RewardProgram, 404);

        return $record;
    }

    private function publishVersionAction(): Action
    {
        return Action::make('publish_version')
            ->label('Publish version')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(fn (): bool => RewardAdminPresenter::staffActor() !== null)
            ->modalHeading('Publish program version')
            ->modalDescription('Immutable rules and terms. Editing an active program creates a new version; existing awards keep their frozen rules.')
            ->schema(fn (): array => $this->publishSchema())
            ->action(function (array $data): void {
                $staff = RewardAdminPresenter::staffActor();
                abort_unless($staff !== null, 403);

                try {
                    $payload = RewardPublishAssembler::assemble($this->programRecord()->mode, $data);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['effective_from' => $exception->getMessage()]);
                }

                try {
                    PublishRewardProgramVersionAction::run(
                        $this->programRecord(),
                        CarbonImmutable::parse((string) $data['effective_from']),
                        $payload['rules'],
                        $payload['terms'],
                        filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                        'user',
                        (string) $staff->getKey(),
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['effective_from' => $exception->getMessage()]);
                }

                Notification::make()->title('Program version published')->success()->send();
            });
    }

    /**
     * Structured publish controls prefilled from the mode preset. No
     * raw JSON: every rule key maps to a typed field, and the assembler
     * builds the validated rules/terms maps on submit.
     *
     * @return list<mixed>
     */
    private function publishSchema(): array
    {
        $mode = $this->programRecord()->mode;
        $preset = RewardPresetBuilders::for($mode);
        $rules = $preset['rules'];
        $terms = $preset['terms'];
        $combined = $mode === RewardProgramMode::Combined;

        $byType = [];

        foreach ($rules['components'] as $component) {
            $byType[$component['type']] = $component;
        }

        $show = static fn (RewardProgramMode $single): bool => $combined || $mode === $single;

        $schema = [
            DateTimePicker::make('effective_from')
                ->label('Effective from (UTC)')
                ->timezone('UTC')
                ->required()
                ->default(now()->toIso8601String()),
        ];

        if ($combined) {
            foreach (['recognition', 'points', 'badge', 'cash', 'benefit'] as $type) {
                $schema[] = Toggle::make('component_'.$type)
                    ->label('Enable '.ucfirst($type))
                    ->default(isset($byType[$type]));
            }
        }

        if ($show(RewardProgramMode::Recognition)) {
            $schema[] = TextInput::make('recognition_template')
                ->label('Recognition template')
                ->maxLength(64)
                ->default($byType['recognition']['template'] ?? 'quiet-thanks');
        }

        if ($show(RewardProgramMode::Points)) {
            $schema[] = TextInput::make('points_amount')
                ->label('Points per award')
                ->numeric()
                ->minValue(1)
                ->maxValue(RewardRuleValidator::MAX_POINTS)
                ->required()
                ->default($byType['points']['points'] ?? 10);
            $schema[] = TextInput::make('points_unit')
                ->label('Points unit')
                ->maxLength(16)
                ->required()
                ->default($byType['points']['unit'] ?? 'pts');
        }

        if ($show(RewardProgramMode::Badges)) {
            $schema[] = TextInput::make('badge_key')
                ->label('Badge key')
                ->maxLength(64)
                ->required()
                ->default($byType['badge']['badge_key'] ?? 'first-share');
        }

        if ($show(RewardProgramMode::Cash)) {
            $schema[] = TextInput::make('cash_minor_units')
                ->label('Cash amount (minor units)')
                ->helperText('Minor units: cents for MYR/USD. 500 = RM 5.00.')
                ->numeric()
                ->minValue(1)
                ->required()
                ->default($byType['cash']['minor_units'] ?? 500);
            $schema[] = Select::make('cash_currency')
                ->label('Cash currency')
                ->options(RewardAdminPresenter::currencyOptions())
                ->required()
                ->default($byType['cash']['currency'] ?? 'MYR');
        }

        if ($show(RewardProgramMode::Benefit)) {
            $schema[] = TextInput::make('benefit_key')
                ->label('Benefit key')
                ->maxLength(64)
                ->required()
                ->default($byType['benefit']['benefit_key'] ?? 'ebook-credit');
            $schema[] = Textarea::make('benefit_conditions')
                ->label('Benefit conditions (one per line)')
                ->rows(2)
                ->required()
                ->default(implode("\n", $byType['benefit']['conditions'] ?? ['Member account in good standing']));
        }

        $schema[] = Select::make('eligibility_outcomes')
            ->label('Eligible outcomes')
            ->options(RewardAdminPresenter::outcomeOptions())
            ->multiple()
            ->required()
            ->default($rules['eligibility']['outcomes']);
        $schema[] = Textarea::make('eligibility_categories')
            ->label('Category slugs (one per line, empty means all)')
            ->rows(2)
            ->default('');
        $schema[] = Select::make('min_verification')
            ->label('Minimum verification')
            ->options(RewardAdminPresenter::verificationOptions())
            ->required()
            ->default($rules['eligibility']['min_verification']);
        $schema[] = Toggle::make('first_only')->label('First-time outcomes only')->default(false);
        $schema[] = Toggle::make('exclude_self_credit')->label('Exclude self credit')->default(true);
        $schema[] = Toggle::make('require_enrollment')->label('Require enrollment')->default(true);
        $schema[] = Toggle::make('require_claimed_identity')->label('Require claimed identity')->default(true);
        $schema[] = TextInput::make('preclaim_lookback_days')
            ->label('Preclaim lookback (days)')
            ->numeric()
            ->minValue(0)
            ->maxValue(90)
            ->required()
            ->default(30);
        $schema[] = TextInput::make('hold_days')
            ->label('Hold (days)')
            ->numeric()
            ->minValue(0)
            ->maxValue(60)
            ->required()
            ->default(0);

        $presetCaps = $rules['caps'];

        for ($row = 1; $row <= 3; $row++) {
            $schema[] = Select::make('cap_scope_'.$row)
                ->label('Cap '.$row.' scope (blank skips)')
                ->options(RewardAdminPresenter::capScopeOptions())
                ->default($presetCaps[$row - 1]['scope'] ?? null);
            $schema[] = TextInput::make('cap_limit_'.$row)
                ->label('Cap '.$row.' limit')
                ->numeric()
                ->minValue(1)
                ->default($presetCaps[$row - 1]['limit'] ?? null);
        }

        $schema[] = Toggle::make('ambiguous_device')->label('Hold ambiguous devices')->default(true);
        $schema[] = Select::make('manual_review_outcomes')
            ->label('Manual review outcomes')
            ->options(RewardAdminPresenter::outcomeOptions())
            ->multiple()
            ->default([]);

        $schema[] = TextInput::make('terms_label')
            ->label('Terms label')
            ->maxLength(191)
            ->required()
            ->default($terms['label']);
        $schema[] = TextInput::make('terms_version_label')
            ->label('Terms version label')
            ->maxLength(32)
            ->required()
            ->default('v1');
        $schema[] = Textarea::make('terms_body')
            ->label('Terms body')
            ->rows(4)
            ->required()
            ->default($terms['body']);
        $schema[] = Textarea::make('terms_earn_basis')
            ->label('Material clause: earn basis')
            ->rows(2)
            ->required()
            ->default($terms['material']['earn_basis']);
        $schema[] = Textarea::make('terms_changes')
            ->label('Material clause: changes')
            ->rows(2)
            ->required()
            ->default($terms['material']['changes']);
        $schema[] = Textarea::make('terms_no_ranking')
            ->label('Material clause: no ranking')
            ->rows(2)
            ->required()
            ->default($terms['material']['no_ranking'] ?? 'No public ranking, streak pressure, or claims of spiritual merit.');

        $schema[] = TextInput::make('reason')->maxLength(191);

        return $schema;
    }

    private function transitionStatusAction(): Action
    {
        return Action::make('transition_status')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (): bool => RewardAdminPresenter::staffActor() !== null)
            ->modalHeading('Change program status')
            ->modalDescription('Lifecycle: draft → ready → active → paused → closed. Ready/active transitions validate program readiness.')
            ->schema([
                Select::make('to')
                    ->label('New status')
                    ->options(RewardAdminPresenter::programStatusOptions())
                    ->required(),
            ])
            ->action(function (array $data): void {
                abort_unless(RewardAdminPresenter::staffActor() !== null, 403);

                try {
                    TransitionRewardProgramStatusAction::run($this->programRecord(), (string) $data['to']);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['to' => $exception->getMessage()]);
                }

                Notification::make()->title('Program status updated')->success()->send();
            });
    }

    private function previewQualificationAction(): Action
    {
        return Action::make('preview_qualification')
            ->label('Preview qualification')
            ->icon(Heroicon::OutlinedEye)
            ->color('info')
            ->visible(fn (): bool => RewardAdminPresenter::staffActor() !== null)
            ->modalHeading('Deterministic qualification preview')
            ->modalDescription('Explains why this deterministic fixture qualifies, holds, or rejects under the applicable version. Nothing is persisted.')
            ->schema([
                Select::make('component')
                    ->options(RewardAdminPresenter::componentOptions())
                    ->required(),
                Select::make('outcome')
                    ->options(RewardAdminPresenter::outcomeOptions())
                    ->required(),
                Select::make('verification')
                    ->options(RewardAdminPresenter::verificationOptions())
                    ->default(RewardVerificationLevel::Accepted->value)
                    ->required(),
                Toggle::make('is_first_time')->label('First-time outcome')->default(true),
                Toggle::make('is_attributed')->label('Attributed')->default(true),
                Toggle::make('is_claimed_identity')->label('Claimed identity')->default(true),
                Toggle::make('self_credit')->label('Actor is the sharer (self credit)')->default(false),
            ])
            ->action(function (array $data, PreviewRewardQualificationAction $preview): void {
                $actorId = '11111111-1111-4111-8111-111111111111';
                $sharerId = ($data['self_credit'] ?? false) ? $actorId : '22222222-2222-4222-8222-222222222222';

                try {
                    $result = $preview->handle(
                        $this->programRecord(),
                        RewardComponent::from((string) $data['component']),
                        [
                            'source_business_key' => 'fixture:preview:'.(string) $data['outcome'],
                            'outcome' => RewardEligibleOutcome::from((string) $data['outcome']),
                            'category' => null,
                            'occurred_at' => CarbonImmutable::now('UTC')->subDay(),
                            'verification' => RewardVerificationLevel::from((string) $data['verification']),
                            'is_first_time' => (bool) ($data['is_first_time'] ?? false),
                            'actor_id' => $actorId,
                            'sharer_id' => $sharerId,
                            'is_claimed_identity' => (bool) ($data['is_claimed_identity'] ?? false),
                            'is_attributed' => (bool) ($data['is_attributed'] ?? false),
                        ],
                        CarbonImmutable::now('UTC'),
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['component' => $exception->getMessage()]);
                }

                $lines = ['Decision: '.$result->decision->value];

                foreach ($result->reasons as $index => $reason) {
                    $lines[] = '- '.$reason->value.': '.($result->explanations[$index] ?? '');
                }

                Notification::make()
                    ->title('Preview: '.$result->decision->value)
                    ->body(implode("\n", $lines))
                    ->info()
                    ->persistent()
                    ->send();
            });
    }
}
