<?php

namespace App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent;

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Filament\EventContributionFormContext;
use App\Features\Events\Api\Filament\EventContributionFormFields;
use App\Features\Events\Api\Filament\EventContributionFormMode;
use App\Features\Events\Api\Filament\EventContributionFormSchema;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\SubmitEventOptionsProvider;
use App\Features\Events\Support\EventContributionFormRules;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Events\Support\SubmitEventFormContext;
use App\Features\Events\Support\SubmitterContactRules;
use App\Features\Institutions\Api\Filament\InstitutionQuickAdd;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Platform\Ui\Forms\Select;
use App\Shared\Media\EventMediaForm;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select as FilamentSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

/**
 * Wizard shell and step composition for the public event submission form.
 *
 * Every editable field comes from the canonical Events feature definition.
 * This class supplies only create-only entrypoint concerns: wizard steps, the
 * attached-occurrence selector, submission country placement, the verified
 * Maps institution quick-add, and the review/submit step.
 */
final readonly class EventSubmissionFormSchema
{
    public const string REVIEW_STEP_ID = 'form.semak-sebelum-hantar::data::wizard-step';

    public const string DEFAULT_SUBMISSION_TIME = EventContributionFormFields::DEFAULT_CUSTOM_TIME;

    public function __construct(
        private SubmitEventFormContext $context,
        private SubmitEventOptionsProvider $options,
        private EventContributionFormSchema $canonical,
    ) {}

    public function form(Schema $schema, ?string $wizardStep): Schema
    {
        $hasScopedInstitution = $this->context->scopedInstitutionId !== null;
        $isReviewStep = $wizardStep === self::REVIEW_STEP_ID;
        $submitButtonLabel = $hasScopedInstitution
            ? __('Publish Institution Event')
            : __('Hantar Majlis untuk Semakan');

        return $schema
            ->model(new Event)
            ->schema([
                $this->buildEventWizard($hasScopedInstitution, $isReviewStep, $submitButtonLabel),
            ])
            ->statePath('data');
    }

    private function buildEventWizard(
        bool $hasScopedInstitution,
        bool $isReviewStep,
        string $submitButtonLabel,
    ): Wizard {
        return Wizard::make([
            $this->buildEventInfoStep(),
            $this->buildScheduleStep(),
            $this->buildOrganizerLocationStep(),
            $this->buildPersonsMediaStep(),
            $this->buildReviewStep($hasScopedInstitution),
        ])
            ->skippable()
            ->persistStepInQueryString()
            ->nextAction(fn (Action $action): Action => $action
                ->label($isReviewStep ? '' : __('Seterusnya'))
                ->livewireTarget("callSchemaComponentMethod('form.data::wizard', 'nextStep')")
                ->hidden($isReviewStep)
            )
            ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                                        <x-filament::button
                                            type="submit"
                                            size="lg"
                                            color="success"
                                            class="w-full"
                                        >
                                            {{ $label }}
                                        </x-filament::button>
                                    BLADE, ['label' => $submitButtonLabel])));
    }

    private function buildEventInfoStep(): Step
    {
        return Step::make(__('Majlis & Topik'))
            ->icon('heroicon-o-document-text')
            ->schema($this->canonical->topicComponents($this->formContext()));
    }

    private function buildScheduleStep(): Step
    {
        return Step::make(__('Tarikh, Masa & Kehadiran'))
            ->icon('heroicon-o-clock')
            ->schema([
                ...$this->canonical->scheduleComponents($this->formContext(), $this->occurrenceSelector()),
                ...$this->canonical->attendanceComponents($this->formContext()),
            ]);
    }

    private function buildOrganizerLocationStep(): Step
    {
        return Step::make(__('Format, Penganjur & Lokasi'))
            ->icon('heroicon-o-building-office')
            ->schema([
                ...$this->canonical->countryComponents($this->formContext()),
                ...$this->canonical->organizerComponents($this->formContext(), $this->verifiedInstitutionQuickAdd(...)),
                ...$this->canonical->locationComponents($this->formContext(), $this->verifiedInstitutionQuickAdd(...)),
            ]);
    }

    private function buildPersonsMediaStep(): Step
    {
        return Step::make(__('Penceramah & Media'))
            ->icon('heroicon-o-user-group')
            ->schema([
                ...$this->canonical->speakerComponents($this->formContext()),
                EventMediaForm::section(),
            ]);
    }

    private function buildReviewStep(bool $hasScopedInstitution): Step
    {
        return Step::make(__('Semak & Hantar'))
            ->id(self::REVIEW_STEP_ID)
            ->icon('heroicon-o-paper-airplane')
            ->schema([
                Hidden::make('captcha_token')
                    ->dehydrated(),

                Section::make(__('Pratonton Penghantaran'))
                    ->description(__('Semak ringkasan ini sebelum anda menghantar.'))
                    ->schema([
                        SchemaView::make('components.pages.submit-event.partials.review-preview')
                            ->viewData(fn (Get $get): array => [
                                'hasReligiousContext' => EventContributionFormRules::hasAgamaKerohanianTopic(
                                    $get('domain_tags') ?? [],
                                ),
                            ]),
                    ]),

                Section::make(__('Maklumat Anda'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextInput::make('submitter_name')
                                    ->label(__('Nama Anda'))
                                    ->required()
                                    ->afterStateUpdatedJs($this->progressUpdateJs())
                                    ->maxLength(100),

                                TextInput::make('submitter_email')
                                    ->label(__('Email'))
                                    ->email()
                                    ->maxLength(255)
                                    ->afterStateUpdatedJs($this->progressUpdateJs())
                                    ->extraAlpineAttributes([
                                        'x-bind:required' => <<<'JS'
                                            ! $get('submitter_phone')
                                        JS,
                                    ])
                                    ->required(fn (Get $get) => ! auth()->check() && empty($get('submitter_phone'))),

                                PhoneInput::make('submitter_phone')
                                    ->label(__('Telefon'))
                                    ->initialCountry('MY')
                                    ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                                    ->inputNumberFormat(PhoneInputNumberType::E164)
                                    ->helperText(__('cth: +60123456789 atau 03-12345678'))
                                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                        if (! filled($value)) {
                                            return;
                                        }

                                        if (! SubmitterContactRules::isValidPhone($value)) {
                                            $fail(__('Nombor telefon tidak sah. Sila semak semula.'));
                                        }
                                    })
                                    ->afterStateUpdatedJs($this->progressUpdateJs())
                                    ->required(fn (Get $get) => ! auth()->check() && empty($get('submitter_email'))),
                            ]),
                    ])
                    ->visible(fn () => ! auth()->check()),

                Section::make(__('Nota untuk Pentadbir'))
                    ->description(__('Pilihan: Tambah maklumat atau permintaan khas untuk moderator.'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('Nota'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder(__('cth: Keperluan khas, maklumat tambahan, atau apa sahaja yang perlu diketahui moderator...'))
                            ->helperText(__('Maksimum 1000 aksara')),
                    ])
                    ->visible(! $hasScopedInstitution),

                Callout::make($hasScopedInstitution ? __('Terbit Serta-Merta') : __('Semakan Moderator'))
                    ->description($hasScopedInstitution
                        ? __('Majlis institusi ini akan diterbitkan terus selepas dihantar dan tidak melalui giliran semakan moderator.')
                        : __('Majlis anda akan disemak oleh moderator kami dalam tempoh 24-48 jam. Anda akan dimaklumkan melalui e-mel setelah majlis diluluskan.'))
                    ->info(),

                ...($this->turnstileConfigured() ? [
                    SchemaView::make('components.pages.submit-event.partials.turnstile'),
                ] : []),
            ]);
    }

    private function formContext(): EventContributionFormContext
    {
        return new EventContributionFormContext(
            mode: EventContributionFormMode::Create,
            eventId: $this->context->eventContainer instanceof Event
                ? (string) $this->context->eventContainer->getKey()
                : null,
            scopedInstitutionId: $this->context->scopedInstitutionId,
            progressUpdateJs: $this->progressUpdateJs(),
            hasEventContainer: $this->context->eventContainer instanceof Event,
            submitter: $this->context->submitter,
        );
    }

    private function occurrenceSelector(): Component
    {
        return Select::make('event_occurrence_id')
            ->label(__('Jadual'))
            ->helperText(__('Pilih jadual majlis untuk sesi ini.'))
            ->visible(fn (): bool => $this->context->eventContainer instanceof Event)
            ->required(fn (): bool => count($this->options->occurrenceOptions($this->context->eventContainer)) > 1)
            ->options(fn (): array => $this->options->occurrenceOptions($this->context->eventContainer))
            ->default(fn (): ?string => $this->context->requestedOccurrenceId ?? $this->options->defaultOccurrenceId($this->context->eventContainer))
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                $this->applyOccurrenceScheduleDefaults($state, $set, $get);
            })
            ->afterStateUpdatedJs($this->progressUpdateJs())
            ->columnSpanFull();
    }

    private function verifiedInstitutionQuickAdd(FilamentSelect $select): FilamentSelect
    {
        return $select
            ->createOptionForm(fn (FilamentSelect $component): array => app(InstitutionQuickAdd::class)->formComponents(
                includeLocationPicker: true,
                requireVerifiedMapsSelection: true,
                mapsSelectionOwnerPath: $component->getStatePath(),
            ))
            ->createOptionAction(fn (Action $action, FilamentSelect $component): Action => $action->mountUsing(
                function (?Schema $schema = null) use ($component): void {
                    if (! $schema instanceof Schema) {
                        throw new RuntimeException('Institution quick-add form is not available.');
                    }

                    $schema->fill();
                    $livewire = $component->getLivewire();

                    if (! $livewire instanceof Create) {
                        throw new RuntimeException('Institution quick-add is only available on event submission.');
                    }

                    $formPath = $schema->getStatePath();
                    $ownerStatePath = $component->getStatePath();
                    $nonce = $livewire->beginInstitutionQuickAddMapsSelection($formPath, $ownerStatePath);
                    data_set($livewire, $formPath.'.address.maps_selection_nonce', $nonce);
                    data_set($livewire, $formPath.'.address.maps_selection_token', null);
                },
            ))
            ->createOptionUsing(function (array $data, Schema $schema, FilamentSelect $component): string {
                $livewire = $component->getLivewire();

                if (! $livewire instanceof Create) {
                    throw new RuntimeException('Institution quick-add is only available on event submission.');
                }

                $formPath = $schema->getStatePath();
                $ownerStatePath = $component->getStatePath();
                $verifiedMapsSelection = $livewire->verifiedInstitutionQuickAddMapsSelection(
                    $formPath,
                    $ownerStatePath,
                    $data,
                );
                $institutionId = app(InstitutionQuickAdd::class)->createOption(
                    $data,
                    $schema,
                    requireVerifiedMapsSelection: true,
                    verifiedMapsSelection: $verifiedMapsSelection,
                );
                $livewire->consumeInstitutionQuickAddMapsSelection($formPath, $ownerStatePath, $data);

                return $institutionId;
            });
    }

    private function applyOccurrenceScheduleDefaults(?string $occurrenceId, Set $set, Get $get): void
    {
        if (! is_string($occurrenceId) || $occurrenceId === '') {
            return;
        }

        $container = $this->context->eventContainer;

        if (! $container instanceof Event) {
            return;
        }

        $occurrence = EventOccurrence::query()
            ->whereKey($occurrenceId)
            ->where('event_id', $container->getKey())
            ->whereNotIn('status', [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED])
            ->first();

        if (! $occurrence instanceof EventOccurrence || ! $occurrence->starts_at instanceof CarbonInterface) {
            return;
        }

        // A valid explicit choice wins; otherwise the occurrence timezone is
        // preserved as the choice when linked to the country (prefill plan),
        // so a later choice cannot reinterpret a UTC-derived wall clock.
        $timing = app(SubmissionTimingPolicy::class);
        $countryId = $timing->resolveSubmissionCountryId($get('submission_country_id'), 'MY');
        $submittedTimezone = $get('submission_timezone');
        $submittedTimezone = is_string($submittedTimezone)
            && in_array($submittedTimezone, $timing->countryTimezones($countryId), true)
            ? $submittedTimezone
            : null;
        $timezone = $submittedTimezone ?? $timing->defaultSubmissionTimezone($countryId, $occurrence->timezone);

        if ($submittedTimezone === null && $timezone !== null) {
            $set('submission_timezone', $timezone);
        }

        $conversionTimezone = $timezone ?? config('app.timezone', 'UTC');
        $startsAt = Carbon::instance($occurrence->starts_at)->setTimezone($conversionTimezone);

        $set('event_date', $startsAt->toDateString());
        $set('prayer_time', EventPrayerTime::LainWaktu->value);
        $set('custom_time', $startsAt->format('H:i'));
        $set('end_time', $occurrence->ends_at instanceof CarbonInterface
            ? Carbon::instance($occurrence->ends_at)->setTimezone($conversionTimezone)->format('H:i')
            : null);
    }

    private function progressUpdateJs(): string
    {
        return "window.dispatchEvent(new CustomEvent('submit-event-progress-updated'))";
    }

    private function turnstileConfigured(): bool
    {
        return (bool) config('services.turnstile.enabled')
            && filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public static function hasCommunityCategorySelection(mixed $categoryIds): bool
    {
        return EventContributionFormRules::hasCommunityCategorySelection($categoryIds);
    }

    public static function categoriesRequirePersons(mixed $categoryIds): bool
    {
        return EventContributionFormRules::categoriesRequirePersons($categoryIds);
    }

    public static function isPrayerTime(mixed $value, EventPrayerTime $expected): bool
    {
        return EventContributionFormRules::isPrayerTime($value, $expected);
    }

    public static function selectedPrimaryOrganizerKind(mixed $organizerKind, mixed $primaryOrganizerId): ?string
    {
        return EventContributionFormRules::selectedPrimaryOrganizerKind($organizerKind, $primaryOrganizerId);
    }

    public static function resolveSubmissionTimezone(mixed $countryId = null, mixed $submittedTimezone = null): string
    {
        $policy = app(SubmissionTimingPolicy::class);
        $resolvedCountryId = $policy->resolveSubmissionCountryId($countryId);

        return $policy->previewSubmissionTimezone($resolvedCountryId, $submittedTimezone);
    }

    /**
     * @return array<string, string>
     */
    public static function submissionTimezoneOptions(mixed $countryId = null): array
    {
        $policy = app(SubmissionTimingPolicy::class);
        $resolvedCountryId = $policy->resolveSubmissionCountryId($countryId);
        $timezones = $policy->countryTimezones($resolvedCountryId);

        return array_combine($timezones, $timezones);
    }

    public static function isSubmissionTimezoneRequired(mixed $countryId = null): bool
    {
        $policy = app(SubmissionTimingPolicy::class);
        $resolvedCountryId = $policy->resolveSubmissionCountryId($countryId);

        return $policy->submissionTimezoneRequired($resolvedCountryId);
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeAgeGroupState(mixed $state): array
    {
        return EventContributionFormRules::normalizeAgeGroupState($state);
    }

    /**
     * @param  array<int, string>  $ageGroups
     * @return array<int, string>
     */
    public static function normalizeAgeGroupSelection(array $ageGroups): array
    {
        return EventContributionFormRules::normalizeAgeGroupSelection($ageGroups);
    }

    /**
     * @return list<string>
     */
    public static function normalizeEventCategoryState(mixed $state): array
    {
        return EventContributionFormRules::normalizeSingleSelectList($state);
    }

    /**
     * @return list<string>
     */
    public static function normalizeDomainTagState(mixed $state): array
    {
        return EventContributionFormRules::normalizeSingleSelectList($state);
    }

    /**
     * One completion check per required field relevant to the current state.
     * Conditional fields are added only while their validation rule is active.
     *
     * @param  array<string, mixed>  $state
     * @return list<bool>
     */
    public static function requiredFieldProgressChecks(array $state, bool $hasScopedInstitution, bool $isAuthenticated): array
    {
        $categoryIds = $state['event_category_ids'] ?? [];
        $topicIds = $state['domain_tags'] ?? [];
        $eventFormat = $state['event_format'] ?? null;
        $isOnline = $eventFormat instanceof EventFormat
            ? $eventFormat === EventFormat::Online
            : $eventFormat === EventFormat::Online->value;
        $prayerTime = $state['prayer_time'] ?? null;
        $organizerId = $state['primary_organizer_id'] ?? null;
        $organizerKind = self::selectedPrimaryOrganizerKind(
            $state['primary_organizer_kind'] ?? null,
            $organizerId,
        );
        $sameAsInstitution = (bool) ($state['location_same_as_institution'] ?? true);
        $locationRequired = ! $isOnline && (
            $organizerKind === 'person' || ! $sameAsInstitution
        );

        $timezoneCountryId = app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($state['submission_country_id'] ?? null);

        $requiredFields = [
            EventContributionFormRules::hasSelection($categoryIds),
            EventContributionFormRules::hasSelection($topicIds),
            filled($state['title'] ?? null),
            filled($state['submission_country_id'] ?? null),
            filled($state['event_date'] ?? null),
            filled($state['event_format'] ?? null),
            filled($state['visibility'] ?? null),
            filled($state['gender'] ?? null),
            EventContributionFormRules::hasSelection($state['age_group'] ?? []),
            EventContributionFormRules::hasSelection($state['languages'] ?? []),
            filled($prayerTime),
        ];

        if (app(SubmissionTimingPolicy::class)->submissionTimezoneRequired($timezoneCountryId)) {
            $requiredFields[] = filled($state['submission_timezone'] ?? null);
        }

        if (self::isPrayerTime($prayerTime, EventPrayerTime::LainWaktu)) {
            $requiredFields[] = filled($state['custom_time'] ?? null);
        }

        if (! $hasScopedInstitution && blank($organizerId)) {
            $requiredFields[] = filled($state['primary_organizer_kind'] ?? null);

            if ($organizerKind === 'institution') {
                $requiredFields[] = filled($state['primary_organizer_institution_id'] ?? null);
            }

            if ($organizerKind === 'person') {
                $requiredFields[] = filled($state['primary_organizer_person_id'] ?? null);
            }
        }

        if ($locationRequired) {
            $requiredFields[] = filled($state['location_type'] ?? null);

            if (($state['location_type'] ?? null) === 'institution') {
                $requiredFields[] = filled($state['location_institution_id'] ?? null);
            }

            if (($state['location_type'] ?? null) === 'venue') {
                $requiredFields[] = filled($state['location_venue_id'] ?? null);
            }
        }

        if (EventContributionFormRules::hasSelection($categoryIds) && self::categoriesRequirePersons($categoryIds)) {
            $requiredFields[] = EventContributionFormRules::hasSelection($state['persons'] ?? []);
        }

        $otherKeyPeople = $state['other_key_people'] ?? [];

        if ($otherKeyPeople instanceof Collection) {
            $otherKeyPeople = $otherKeyPeople->all();
        }

        if (! is_array($otherKeyPeople)) {
            $otherKeyPeople = [];
        }

        foreach ($otherKeyPeople as $keyPerson) {
            if (! is_array($keyPerson)) {
                continue;
            }

            $requiredFields[] = filled($keyPerson['role_code'] ?? null);
            $requiredFields[] = filled($keyPerson['involveable_id'] ?? null)
                || filled($keyPerson['display_name'] ?? null);
            $requiredFields[] = filled($keyPerson['visibility'] ?? null);
        }

        if (! $isAuthenticated) {
            $requiredFields[] = filled($state['submitter_name'] ?? null);
            $requiredFields[] = filled($state['submitter_email'] ?? null)
                || filled($state['submitter_phone'] ?? null);
        }

        return $requiredFields;
    }
}
