<?php

namespace App\Livewire\Pages\SubmitEvent;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\SpaceEligibilityResolver;
use App\Data\Events\SubmitEventFormContext;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\TaxonomyTerm\DomainTermCode;
use App\Forms\Components\Select;
use App\Forms\InstitutionFormSchema;
use App\Forms\PersonFormSchema;
use App\Forms\ReferenceFormSchema;
use App\Forms\VenueFormSchema;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Submission\EntitySubmissionAccess;
use App\Support\Submission\SubmissionTimingPolicy;
use App\Support\Submission\SubmitEventOptionsProvider;
use App\Support\Submission\SubmitterContactRules;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Throwable;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

/**
 * Field definitions and step builders for the public event submission form.
 *
 * Pure function of the per-request context plus live Filament Get/Set state:
 * every closure re-evaluates from current form state, never from a snapshot.
 */
final class EventSubmissionFormSchema
{
    public const string REVIEW_STEP_ID = 'form.semak-sebelum-hantar::data::wizard-step';

    public const string DEFAULT_SUBMISSION_TIME = '20:00';

    private const string AGAMA_KEROHANIAN_CODE = DomainTermCode::AgamaKerohanian->value;

    public function __construct(
        private readonly SubmitEventFormContext $context,
        private readonly SubmitEventOptionsProvider $options,
    ) {}

    public function form(Schema $schema, ?string $wizardStep): Schema
    {
        $hasScopedInstitution = $this->context->scopedInstitution instanceof Institution;
        $hasScopedInstitutionJs = $hasScopedInstitution ? 'true' : 'false';
        $isReviewStep = $wizardStep === self::REVIEW_STEP_ID;
        $submitButtonLabel = $hasScopedInstitution
            ? __('Publish Institution Event')
            : __('Hantar Majlis untuk Semakan');

        $wizard = $this->buildEventWizard($hasScopedInstitution, $hasScopedInstitutionJs, $isReviewStep, $submitButtonLabel);

        return $schema
            ->model(new Event)
            ->schema([
                $wizard,
            ])
            ->statePath('data');
    }

    private function buildEventWizard(
        bool $hasScopedInstitution,
        string $hasScopedInstitutionJs,
        bool $isReviewStep,
        string $submitButtonLabel,
    ): Wizard {
        return Wizard::make([
            $this->buildEventInfoStep(),
            $this->buildScheduleStep(),
            $this->buildOrganizerLocationStep($hasScopedInstitution, $hasScopedInstitutionJs),
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
            ->schema([...$this->getEventAboutFields(), ...$this->getTopicDetailFields()]);
    }

    /**
     * @return array<int, mixed>
     */
    private function getEventAboutFields(): array
    {
        return [
            Select::make('title')
                ->native(false)
                ->label(__('Tajuk Majlis'))
                ->required()
                ->searchable()
                ->allowHtml()
                ->live()
                ->afterStateUpdatedJs($this->progressUpdateJs())
                ->getSearchResultsUsing(function (string $search): array {
                    if ($search === '' || $search === '0') {
                        return [];
                    }

                    $results = Event::query()
                        ->whereLike('title', "%{$search}%")
                        ->where('status', 'approved')
                        ->where('visibility', EventVisibility::Public->value)
                        ->whereNotNull('published_at')
                        ->limit(10)
                        ->pluck('title', 'title')
                        ->toArray();

                    $exactMatch = collect($results)->contains(fn ($value) => mb_strtolower($value) === mb_strtolower($search));
                    $results = array_map(e(...), $results);

                    if (! $exactMatch) {
                        $results = ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".e(__('Tambah'))." '".e($search)."'</span>"] + $results;
                    }

                    return $results;
                })
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    if (! is_string($value)) {
                        return null;
                    }

                    if (str_starts_with($value, '__quick_add__')) {
                        return e(substr($value, strlen('__quick_add__')));
                    }

                    return e($value);
                })
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if (is_string($state) && str_starts_with($state, '__quick_add__')) {
                        $state = substr($state, strlen('__quick_add__'));
                        $set('title', $state);
                    }

                    if (! is_string($state) || blank($state)) {
                        return;
                    }

                    $existingEvent = Event::query()
                        ->where('title', $state)
                        ->where('status', 'approved')
                        ->where('visibility', EventVisibility::Public->value)
                        ->whereNotNull('published_at')
                        ->with(['classifications', 'references'])
                        ->latest()
                        ->first();

                    if (! $existingEvent) {
                        return;
                    }

                    $termsByTaxonomy = $existingEvent->classifications->groupBy('taxonomy_code');

                    $set('event_category_ids', $termsByTaxonomy->get('event_category', collect())->pluck('event_term_id')->filter()->values()->first());

                    if ($termsByTaxonomy->has(EventTaxonomyCode::Domain->value)) {
                        $set('domain_tags', $termsByTaxonomy->get(EventTaxonomyCode::Domain->value)->pluck('event_term_id')->filter()->values()->first());
                    }
                    if ($termsByTaxonomy->has(EventTaxonomyCode::Discipline->value)) {
                        $set('discipline_tags', $termsByTaxonomy->get(EventTaxonomyCode::Discipline->value)->pluck('event_term_id')->filter()->values()->all());
                    }
                    if ($termsByTaxonomy->has(EventTaxonomyCode::Source->value)) {
                        $set('source_tags', $termsByTaxonomy->get(EventTaxonomyCode::Source->value)->pluck('event_term_id')->filter()->values()->all());
                    }
                    if ($termsByTaxonomy->has(EventTaxonomyCode::Issue->value)) {
                        $set('issue_tags', $termsByTaxonomy->get(EventTaxonomyCode::Issue->value)->pluck('event_term_id')->filter()->values()->all());
                    }

                    if ($existingEvent->references->isNotEmpty()) {
                        $set(
                            'references',
                            $existingEvent->references
                                ->pluck('id')
                                ->filter()
                                ->values()
                                ->all(),
                        );
                    }
                })
                ->placeholder(__('Cari atau masukkan tajuk majlis...')),

            Select::make('event_category_ids')
                ->label(__('Jenis Majlis'))
                ->placeholder(__('Pilih kategori…'))
                ->required()
                ->live()
                ->afterStateUpdatedJs($this->progressUpdateJs())
                ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                    if (self::hasCommunityCategorySelection($state)) {
                        $set('event_format', EventFormat::Physical->value);
                    }

                    $this->applyContextualDefaults($get, $set);
                })
                ->options(app(EventCategoryCatalog::class)->options())
                ->preload()
                ->native(false)
                ->dynamicOptions(false),

            $this->domainTopicField(),

            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('discipline_tags')
                        ->native(false)
                        ->label(__('Topik lebih khusus'))
                        ->helperText(__('Contoh: Tafsir, Matematik, atau Machine Learning.'))
                        ->placeholder(__('Pilih atau taip untuk tambah bidang…'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->allowHtml()
                        ->options(fn (Get $get): array => array_map(e(...), $this->options->taxonomyTermOptionsForDomain(
                            EventTaxonomyCode::Discipline,
                            is_string($domain = $get('domain_tags')) ? $domain : null,
                        )))
                        ->getSearchResultsUsing(function (string $search, ?Get $get = null): array {
                            if (blank($search)) {
                                return [];
                            }

                            $domainId = $get instanceof Get ? (is_string($d = $get('domain_tags')) ? $d : null) : null;
                            $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Discipline->value)->value('id');
                            $results = EventTerm::query()
                                ->where('event_taxonomy_id', $taxonomyId)
                                ->where('is_active', true)
                                ->when($domainId !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($domainId): void {
                                    $query->whereJsonContains('metadata->domain_ids', $domainId)
                                        ->orWhereNull('metadata->domain_ids');
                                }))
                                ->whereLike('name', "%{$search}%")
                                ->orderBy('sort_order')
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->toArray();

                            return ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".e(__('Tambah'))." '".e($search)."'</span>"] + array_map(e(...), $results);
                        })
                        ->getOptionLabelsUsing(function (array $values): array {
                            $labels = [];
                            $uuids = [];

                            foreach ($values as $value) {
                                if (is_string($value) && ! Str::isUuid($value)) {
                                    $labels[$value] = $value;
                                } else {
                                    $uuids[] = $value;
                                }
                            }

                            if ($uuids !== []) {
                                $labels = array_merge($labels, EventTerm::whereIn('id', $uuids)->pluck('name', 'id')->all());
                            }

                            return array_map(e(...), $labels);
                        })
                        ->afterStateUpdatedJs(<<<'JS'
                            if (Array.isArray($state)) {
                                const hasQuickAdd = $state.some(v => typeof v === 'string' && v.startsWith('__quick_add__'));
                                if (hasQuickAdd) {
                                    const cleaned = $state.map(v => (typeof v === 'string' && v.startsWith('__quick_add__')) ? v.substring(13) : v);
                                    $set('discipline_tags', cleaned);
                                    $nextTick(() => {
                                        const wrapper = $el.querySelector('[wire\\:ignore]');
                                        if (wrapper) {
                                            Alpine.$data(wrapper)?.select?.closeDropdown();
                                        }
                                    });
                                }
                            }
                        JS),
                ]),

            RichEditor::make('description')
                ->label(__('Keterangan'))
                ->maxLength(5000)
                ->disableToolbarButtons(['table'])
                ->floatingToolbars([])
                ->placeholder(__('Terangkan mengenai majlis, topik yang akan dikupas, dll.')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function getScheduleFields(): array
    {
        return [
            Section::make(__('Tarikh & Masa'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'md' => 8])
                        ->schema([
                            Select::make('event_occurrence_id')
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
                                ->columnSpanFull(),

                            DatePicker::make('event_date')
                                ->label(__('Tarikh'))
                                ->required()
                                ->native()
                                ->minDate(fn (Get $get): string => Carbon::now(self::resolveSubmissionTimezone($get('submission_country_id'), $get('submission_timezone')))->toDateString())
                                ->live()
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    $this->applyContextualDefaults($get, $set);
                                })
                                ->columnSpan(['default' => 1, 'md' => 2]),

                            Select::make('prayer_time')
                                ->native(false)
                                ->label(__('Waktu'))
                                ->required()
                                ->live()
                                ->default(EventPrayerTime::LainWaktu->value)
                                ->afterStateUpdatedJs(<<<'JS'
                                    if ($state !== 'lain_waktu') {
                                        $set('custom_time', null)
                                    }
                                JS)
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->options(function (Get $get): array {
                                    $eventDate = $get('event_date');
                                    $countryId = $get('submission_country_id');
                                    $timezone = $get('submission_timezone');

                                    return collect(EventPrayerTime::cases())
                                        ->filter(fn (EventPrayerTime $case): bool => $this->isPrayerTimeAvailable($case, $eventDate, $countryId, $timezone))
                                        ->mapWithKeys(fn (EventPrayerTime $case) => [$case->value => $case->getLabel()])
                                        ->toArray();
                                })
                                ->columnSpan(['default' => 1, 'md' => 2]),

                            TimePicker::make('custom_time')
                                ->label(__('Masa Mula'))
                                ->helperText(__('Pilih masa mula majlis'))
                                ->timezone('UTC')
                                ->native()
                                ->seconds(false)
                                ->minutesStep(5)
                                ->afterStateUpdatedJs(str_replace(
                                    '__END_TIME_VALIDATION_MESSAGE__',
                                    Js::from(__('Masa akhir mestilah selepas masa mula.'))->toHtml(),
                                    <<<'JS'
                                    const customTime = $state;
                                    const endTime = $get('end_time');
                                    const prayerTime = $get('prayer_time');

                                    if (prayerTime === 'lain_waktu' && customTime && endTime) {
                                        const startParts = customTime.split(':');
                                        const endParts = endTime.split(':');

                                        const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1] || 0);
                                        const endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1] || 0);

                                        if (endMinutes <= startMinutes) {
                                            $set('end_time', null);
                                            new FilamentNotification()
                                                .title(__END_TIME_VALIDATION_MESSAGE__)
                                                .warning()
                                                .send();
                                        }
                                    }
                                JS
                                ))
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->visible(fn (Get $get): bool => self::isPrayerTime($get('prayer_time'), EventPrayerTime::LainWaktu))
                                ->required(fn (Get $get): bool => self::isPrayerTime($get('prayer_time'), EventPrayerTime::LainWaktu))
                                ->markAsRequired()
                                ->columnSpan(['default' => 1, 'md' => 2])
                                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $eventDate = $get('event_date');
                                    $timezone = self::resolveSubmissionTimezone($get('submission_country_id'), $get('submission_timezone'));
                                    $now = Carbon::now($timezone);

                                    if (! $eventDate || ! $value) {
                                        return;
                                    }

                                    try {
                                        $eventDay = Carbon::parse($eventDate, $timezone)->startOfDay();
                                    } catch (Throwable) {
                                        return;
                                    }

                                    if ($eventDay->isSameDay($now)) {
                                        $timeParts = explode(':', $value);
                                        $selectedTime = $eventDay->copy()
                                            ->setHour((int) $timeParts[0])
                                            ->setMinute((int) $timeParts[1]);

                                        if ($selectedTime->lessThan($now)) {
                                            $fail(__('Masa yang dipilih tidak boleh pada masa lalu untuk majlis hari ini.'));
                                        }
                                    }
                                }),

                            TimePicker::make('end_time')
                                ->label(__('Masa Akhir'))
                                ->helperText(__('Pilihan: Bila majlis dijangka tamat.'))
                                ->timezone('UTC')
                                ->native()
                                ->seconds(false)
                                ->minutesStep(5)
                                ->afterStateUpdatedJs(str_replace(
                                    '__END_TIME_VALIDATION_MESSAGE__',
                                    Js::from(__('Masa akhir mestilah selepas masa mula.'))->toHtml(),
                                    <<<'JS'
                                    const customTime = $get('custom_time');
                                    const endTime = $state;
                                    const prayerTime = $get('prayer_time');
                                    // Client-side only for custom times (both clocks user-entered).
                                    // Prayer labels compare server-side against a fresh cache-only
                                    // resolution: the hidden hint is a snapshot that deferred
                                    // warming can supersede, so it must never clear input here.
                                    if (prayerTime === 'lain_waktu' && customTime && endTime) {
                                        const startParts = customTime.split(':');
                                        const endParts = endTime.split(':');

                                        const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1] || 0);
                                        const endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1] || 0);

                                        if (endMinutes <= startMinutes) {
                                            $set('end_time', null);
                                            new FilamentNotification()
                                                .title(__END_TIME_VALIDATION_MESSAGE__)
                                                .warning()
                                                .send();
                                        }
                                    }
                                JS
                                ))
                                ->columnSpan(['default' => 1, 'md' => 2])
                                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    if (! $value) {
                                        return;
                                    }

                                    $prayerTimeRaw = $get('prayer_time');
                                    $startTime = $this->resolveStartTimeForComparison(
                                        $prayerTimeRaw,
                                        $get('custom_time'),
                                        $this->previewStartsForComparison($get('prayer_preview')),
                                        $this->comparisonFormState($get)
                                    );

                                    if ($startTime === null) {
                                        return;
                                    }

                                    $startParts = explode(':', $startTime);
                                    $endParts = explode(':', (string) $value);

                                    $startMinutes = ((int) $startParts[0]) * 60 + ((int) ($startParts[1] ?? 0));
                                    $endMinutes = ((int) $endParts[0]) * 60 + ((int) ($endParts[1] ?? 0));

                                    if ($endMinutes <= $startMinutes) {
                                        $fail(__('Masa akhir mestilah selepas masa mula.'));
                                    }
                                }),

                            Hidden::make('prayer_preview')
                                ->dehydrated(false),
                        ]),
                ]),

            Section::make(__('Kehadiran'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Select::make('gender')
                                ->native(false)
                                ->label(__('Jantina'))
                                ->required()
                                ->options(EventGenderRestriction::class)
                                ->default(EventGenderRestriction::All)
                                ->afterStateUpdatedJs($this->progressUpdateJs()),

                            Select::make('age_group')
                                ->native(false)
                                ->label(__('Peringkat Umur'))
                                ->placeholder(__('Pilih peringkat umur'))
                                ->required()
                                ->options(EventAgeGroup::class)
                                ->closeOnSelect()
                                ->multiple()
                                ->afterStateUpdatedJs(<<<'JS'
                            const ageGroups = Array.isArray($state) ? $state : [];
                            const previousAgeGroups = Array.isArray($old) ? $old : [];
                            const allAges = 'all_ages';
                            const specificAgeGroups = ['adults', 'youth', 'children', 'warga_emas'];
                            let normalizedAgeGroups = ageGroups;

                            if (ageGroups.length === 1 && ageGroups[0] === allAges) {
                                normalizedAgeGroups = [allAges];
                            } else if (ageGroups.includes(allAges) && ! previousAgeGroups.includes(allAges)) {
                                normalizedAgeGroups = [allAges];
                            } else if (ageGroups.includes(allAges)) {
                                normalizedAgeGroups = ageGroups.filter((group) => group !== allAges);
                            } else if (specificAgeGroups.every((group) => ageGroups.includes(group))) {
                                normalizedAgeGroups = [allAges];
                            }

                            if (JSON.stringify(normalizedAgeGroups) !== JSON.stringify(ageGroups)) {
                                $set('age_group', normalizedAgeGroups);
                            }

                            if (normalizedAgeGroups.includes('children') || normalizedAgeGroups.includes(allAges)) {
                                $set('children_allowed', true);
                            }
                        JS)
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->afterStateUpdated(function (mixed $state, Set $set): void {
                                    $normalizedAgeGroups = self::normalizeAgeGroupState($state);
                                    $ageGroups = self::normalizeAgeGroupSelection($normalizedAgeGroups);

                                    if ($ageGroups !== $normalizedAgeGroups) {
                                        $set('age_group', $ageGroups);
                                    }

                                    if (
                                        in_array(EventAgeGroup::Children->value, $ageGroups, true) ||
                                        in_array(EventAgeGroup::AllAges->value, $ageGroups, true)
                                    ) {
                                        $set('children_allowed', true);
                                    }
                                }),

                            Select::make('languages')
                                ->native(false)
                                ->label(__('Bahasa'))
                                ->helperText(__('Bahasa yang akan digunakan dalam majlis.'))
                                ->placeholder(__('Pilih bahasa'))
                                ->closeOnSelect()
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => $this->options->languageOptions())
                                ->afterStateUpdatedJs($this->progressUpdateJs()),

                            Toggle::make('children_allowed')
                                ->label(__('Kanak-kanak Dibenarkan'))
                                ->helperText(__('Adakah ibu bapa boleh membawa anak kecil ke majlis ini?'))
                                ->default(true)
                                ->inline(false)
                                ->disabled(function (Get $get): bool {
                                    $ageGroups = self::normalizeAgeGroupState($get('age_group'));

                                    return in_array(EventAgeGroup::Children->value, $ageGroups, true) ||
                                        in_array(EventAgeGroup::AllAges->value, $ageGroups, true);
                                })
                                ->extraAlpineAttributes([
                                    'x-bind:disabled' => <<<'JS'
                                ($get('age_group') || []).includes('children') || ($get('age_group') || []).includes('all_ages')
                            JS,
                                ])
                                ->dehydrated(),

                            Toggle::make('is_muslim_only')
                                ->label(__('Terbuka untuk Muslim Sahaja'))
                                ->helperText(__('Jika tidak ditanda, majlis dianggap terbuka kepada Muslim dan bukan Muslim.'))
                                ->inline(false)
                                ->default(false),
                        ]),
                ]),
        ];
    }

    private function buildScheduleStep(): Step
    {
        return Step::make(__('Tarikh, Masa & Kehadiran'))
            ->icon('heroicon-o-clock')
            ->schema($this->getScheduleFields());
    }

    private function domainTopicField(): Select
    {
        return Select::make('domain_tags')
            ->label(__('Topik / bidang'))
            ->helperText(__('Wajib dipilih. Bidang ini menentukan soalan tambahan yang akan dipaparkan.'))
            ->placeholder(__('Pilih topik…'))
            ->required()
            ->live()
            ->afterStateUpdatedJs($this->progressUpdateJs())
            ->searchable(false)
            ->preload()
            ->native(false)
            ->dynamicOptions(false)
            ->getOptionLabelUsing(function ($value): ?string {
                if (is_string($value) && ! Str::isUuid($value)) {
                    return $value;
                }

                return EventTerm::where('id', $value)->value('name');
            })
            ->options(fn (): array => $this->options->tagOptions(
                type: EventTaxonomyCode::Domain,
                cachePrefix: 'submit_tags_domain',
            ))
            ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                $this->applyContextualDefaults($get, $set);
                $set('discipline_tags', []);
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function getTopicDetailFields(): array
    {
        return [
            Group::make()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Select::make('source_tags')
                                ->visible(fn (Get $get): bool => $this->hasAgamaKerohanianTopic($get('domain_tags')))
                                ->closeOnSelect()
                                ->label(__('Sumber Utama'))
                                ->placeholder(__('Pilih sumber…'))
                                ->multiple()
                                ->preload()
                                ->searchable(false)
                                ->native(false)
                                ->getOptionLabelsUsing(function (array $values): array {
                                    $labels = [];
                                    $uuids = [];

                                    foreach ($values as $value) {
                                        if (is_string($value) && ! Str::isUuid($value)) {
                                            $labels[$value] = $value;
                                        } else {
                                            $uuids[] = $value;
                                        }
                                    }

                                    if ($uuids !== []) {
                                        $labels = array_merge($labels, EventTerm::whereIn('id', $uuids)->pluck('name', 'id')->all());
                                    }

                                    return $labels;
                                })
                                ->options(fn (): array => $this->options->tagOptions(
                                    type: EventTaxonomyCode::Source,
                                    cachePrefix: 'submit_tags_source',
                                )),

                            Select::make('issue_tags')
                                ->native(false)
                                ->label(__('Tema / Isu'))
                                ->helperText(__('Pilih tema supaya mudah dicari.'))
                                ->placeholder(__('Pilih atau taip untuk tambah tema…'))
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->allowHtml()
                                ->options(fn (Get $get): array => array_map(e(...), $this->options->taxonomyTermOptionsForDomain(
                                    EventTaxonomyCode::Issue,
                                    is_string($domain = $get('domain_tags')) ? $domain : null,
                                )))
                                ->getSearchResultsUsing(function (string $search, ?Get $get = null): array {
                                    if (blank($search)) {
                                        return [];
                                    }

                                    $domainId = $get instanceof Get ? (is_string($d = $get('domain_tags')) ? $d : null) : null;
                                    $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Issue->value)->value('id');
                                    $results = EventTerm::query()
                                        ->where('event_taxonomy_id', $taxonomyId)
                                        ->where('is_active', true)
                                        ->when($domainId !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($domainId): void {
                                            $query->whereJsonContains('metadata->domain_ids', $domainId)
                                                ->orWhereNull('metadata->domain_ids');
                                        }))
                                        ->whereLike('name', "%{$search}%")
                                        ->orderBy('sort_order')
                                        ->limit(20)
                                        ->pluck('name', 'id')
                                        ->toArray();

                                    return ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".e(__('Tambah'))." '".e($search)."'</span>"] + array_map(e(...), $results);
                                })
                                ->getOptionLabelsUsing(function (array $values): array {
                                    $labels = [];
                                    $uuids = [];

                                    foreach ($values as $value) {
                                        if (is_string($value) && ! Str::isUuid($value)) {
                                            $labels[$value] = $value;
                                        } else {
                                            $uuids[] = $value;
                                        }
                                    }

                                    if ($uuids !== []) {
                                        $labels = array_merge($labels, EventTerm::whereIn('id', $uuids)->pluck('name', 'id')->all());
                                    }

                                    return array_map(e(...), $labels);
                                })
                                ->afterStateUpdatedJs(<<<'JS'
                                    if (Array.isArray($state)) {
                                        const hasQuickAdd = $state.some(v => typeof v === 'string' && v.startsWith('__quick_add__'));
                                        if (hasQuickAdd) {
                                            const cleaned = $state.map(v => (typeof v === 'string' && v.startsWith('__quick_add__')) ? v.substring(13) : v);
                                            $set('issue_tags', cleaned);
                                            $nextTick(() => {
                                                const wrapper = $el.querySelector('[wire\\:ignore]');
                                                if (wrapper) {
                                                    Alpine.$data(wrapper)?.select?.closeDropdown();
                                                }
                                            });
                                        }
                                    }
                                JS),
                        ]),

                    Select::make('references')
                        ->visible(fn (Get $get): bool => $this->hasAgamaKerohanianTopic($get('domain_tags')))
                        ->label(__('Rujukan Kitab'))
                        ->helperText(__('Kitab atau buku rujukan yang digunakan.'))
                        ->placeholder(__('Cari atau pilih rujukan…'))
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->native(false)
                        ->relationship('references', 'title', fn (Builder $query) => Reference::applyPublicVisibility($query)->with('parentReference.parentReference'))
                        ->getSearchResultsUsing(fn (string $search): array => ReferenceFormSchema::searchOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => ReferenceFormSchema::selectedLabels($values))
                        ->createOptionForm(ReferenceFormSchema::quickCreateComponents())
                        ->createOptionUsing(fn (array $data, Schema $schema): string => ReferenceFormSchema::createPending($data, $schema)),
                ]),
        ];
    }

    private function buildOrganizerLocationStep(bool $hasScopedInstitution, string $hasScopedInstitutionJs): Step
    {
        return Step::make(__('Format, Penganjur & Lokasi'))
            ->icon('heroicon-o-building-office')
            ->schema($this->getOrganizerLocationFields($hasScopedInstitution, $hasScopedInstitutionJs));
    }

    /**
     * @return array<int, mixed>
     */
    private function getOrganizerLocationFields(bool $hasScopedInstitution, string $hasScopedInstitutionJs): array
    {
        return [
            Section::make(__('Negara'))
                ->schema([
                    Select::make('submission_country_id')
                        ->native(false)
                        ->label(__('Country'))
                        ->required()
                        ->options(fn (): array => app(SelectionCatalogCache::class)->rememberAddressOptions(
                            'countries',
                            static fn (): array => AddressCountry::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all(),
                        ))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $this->syncSubmissionTimezoneForCountry($get, $set);
                            $this->applyContextualDefaults($get, $set);
                            $this->clearCountryMismatchedEntitySelections($get, $set);
                        }),

                    Select::make('submission_timezone')
                        ->native(false)
                        ->label(__('Submission timezone'))
                        ->helperText(__('Select the event timezone for this country.'))
                        ->placeholder(__('Pilih zon waktu…'))
                        ->options(fn (Get $get): array => self::submissionTimezoneOptions($get('submission_country_id')))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get): bool => self::isSubmissionTimezoneRequired($get('submission_country_id')))
                        ->required(fn (Get $get): bool => self::isSubmissionTimezoneRequired($get('submission_country_id')))
                        ->dehydratedWhenHidden()
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $this->applyContextualDefaults($get, $set);
                        }),
                ]),

            Section::make(__('Format & Pautan'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Radio::make('event_format')
                                ->label(__('Format Majlis'))
                                ->required()
                                ->options(EventFormat::class)
                                ->default(EventFormat::Physical)
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    if ($state === EventFormat::Online || $state === EventFormat::Online->value) {
                                        $set('space_ids', []);
                                    }
                                })
                                ->afterStateUpdatedJs("if (\$state === 'online') { \$set('space_ids', []); }")
                                ->disableOptionWhen(
                                    fn (string $value, Get $get): bool => self::hasCommunityCategorySelection($get('event_category_ids'))
                                    && $value !== EventFormat::Physical->value
                                )
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->inline(),

                            Radio::make('visibility')
                                ->label(__('Keterlihatan'))
                                ->required()
                                ->options(EventVisibility::class)
                                ->default(EventVisibility::Public)
                                ->afterStateUpdatedJs($this->progressUpdateJs())
                                ->hidden()
                                ->dehydratedWhenHidden()
                                ->inline(),

                            TextInput::make('event_url')
                                ->label(__('Pautan Majlis'))
                                ->url()
                                ->maxLength(255)
                                ->placeholder(__('https://example.com/event')),

                            TextInput::make('live_url')
                                ->label(__('Pautan Siaran Langsung'))
                                ->url()
                                ->maxLength(255)
                                ->placeholder(__('https://youtube.com/...'))
                                ->visibleJs(<<<'JS'
                                    ['online', 'hybrid'].includes($get('event_format'))
                                    JS),
                        ]),
                ]),

            Section::make(__('Penganjur'))
                ->schema([
                    Hidden::make('primary_organizer_id')
                        ->afterStateUpdatedJs($this->progressUpdateJs()),

                    Radio::make('primary_organizer_kind')
                        ->label(__('Jenis Penganjur'))
                        ->required(fn (Get $get): bool => ! $hasScopedInstitution && ! filled($get('primary_organizer_id')))
                        ->options([
                            'institution' => __('Institusi'),
                            'person' => __('Penceramah'),

                        ])
                        ->default('institution')
                        ->afterStateUpdatedJs(<<<'JS'
                            if ($state !== 'institution') {
                                $set('primary_organizer_institution_id', null)
                            }

                            if ($state !== 'person') {
                                $set('primary_organizer_person_id', null)
                            }

                            $set('primary_organizer_id', null)
                            $set('space_ids', [])
                        JS)
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->inline()
                        ->visible(! $hasScopedInstitution)
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state !== 'institution') {
                                $set('primary_organizer_institution_id', null);
                            }

                            if ($state !== 'person') {
                                $set('primary_organizer_person_id', null);
                            }
                            $set('primary_organizer_id', null);
                            $set('space_ids', []);
                        }),

                    Select::make('primary_organizer_institution_id')
                        ->native(false)
                        ->label(__('Institusi'))
                        ->options(fn (Get $get): array => $this->options->institutionOptions(
                            $this->context->submitter,
                            self::resolveSubmissionCountryId($get('submission_country_id')),
                            $this->context->scopedInstitution,
                        ))
                        ->searchable()
                        ->preload()
                        ->disabled($hasScopedInstitution)
                        ->dehydrated()
                        ->live()
                        ->visibleJs($hasScopedInstitutionJs." || \$get('primary_organizer_kind') === 'institution'")
                        ->extraAlpineAttributes([
                            'x-bind:required' => <<<'JS'
                                $get('primary_organizer_kind') === 'institution' && ! $get('primary_organizer_id')
                            JS,
                        ])
                        ->afterStateUpdatedJs(<<<'JS'
                            const organizerId = $state || null;
                            $set('primary_organizer_id', organizerId);

                            if ($get('location_same_as_institution')) {
                                $set('location_institution_id', organizerId);
                                $set('location_venue_id', null);
                                $set('space_ids', []);
                            }
                        JS)
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->required(fn (Get $get): bool => self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'institution' && ! filled($get('primary_organizer_id')))
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            $organizerId = is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null;

                            $set('primary_organizer_id', $organizerId);

                            if ((bool) $get('location_same_as_institution')) {
                                $set('location_institution_id', $organizerId);
                                $set('location_venue_id', null);
                                $set('space_ids', []);
                            }
                        })
                        ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                    Select::make('primary_organizer_person_id')
                        ->native(false)
                        ->label(__('Penceramah'))
                        ->options(fn (): array => $this->options->personOptions($this->context->submitter))
                        ->searchable()
                        ->preload()
                        ->visibleJs("! {$hasScopedInstitutionJs} && \$get('primary_organizer_kind') === 'person'")
                        ->extraAlpineAttributes([
                            'x-bind:required' => <<<'JS'
                                $get('primary_organizer_kind') === 'person' && ! $get('primary_organizer_id')
                            JS,
                        ])
                        ->required(fn (Get $get): bool => self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' && ! filled($get('primary_organizer_id')))
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('primary_organizer_id', is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null);
                        })
                        ->afterStateUpdatedJs(<<<'JS'
                                                    $set('primary_organizer_id', $state || null)

                                                    if ($state) {
                                                        const currentPersons = $get('persons') || []
                                                        if (!currentPersons.includes($state)) {
                                                            $set('persons', [...currentPersons, $state])
                                                        }
                                                    }
                                                    JS)
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->createOptionForm(PersonFormSchema::createOptionForm())
                        ->createOptionUsing(fn (array $data, Schema $schema, Set $set, Get $get): string => PersonFormSchema::createOptionUsing($data, $schema)),
                ]),

            Section::make(__('Lokasi'))
                ->visibleJs(<<<'JS'
                            $get('event_format') !== 'online' && ($get('primary_organizer_kind') || $get('primary_organizer_id'))
                            JS)
                ->schema([
                    Toggle::make('location_same_as_institution')
                        ->label(__('Sama seperti institusi penganjur'))
                        ->default(true)
                        ->inline(false)
                        ->live()
                        ->visibleJs($hasScopedInstitutionJs." || \$get('primary_organizer_kind') === 'institution'")
                        ->afterStateUpdatedJs("if ({$hasScopedInstitutionJs}) {
                                        if (\$state) {
                                            \$set('location_type', 'institution')
                                            \$set('location_institution_id', \$get('primary_organizer_institution_id'))
                                            \$set('location_venue_id', null)
                                        } else {
                                            \$set('location_type', 'venue')
                                            \$set('location_institution_id', null)
                                        }
                                    }
                                    \$set('space_ids', [])")
                        ->afterStateUpdated(function (Get $get, Set $set, bool $state) use ($hasScopedInstitution): void {
                            $set('space_ids', []);

                            if ($state) {
                                $set('location_type', 'institution');
                                $set('location_institution_id', $get('primary_organizer_institution_id'));
                                $set('location_venue_id', null);
                            } elseif ($hasScopedInstitution) {
                                $set('location_type', 'venue');
                                $set('location_institution_id', null);
                            }
                        })
                        ->afterStateUpdatedJs($this->progressUpdateJs()),

                    Radio::make('location_type')
                        ->label(__('Jenis Lokasi'))
                        ->options([
                            'institution' => __('Institusi'),
                            'venue' => __('Tempat'),
                        ])
                        ->inline()
                        ->default('institution')
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('space_ids', []))
                        ->afterStateUpdatedJs("\$set('space_ids', [])")
                        ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution'))")
                        ->required(fn (Get $get): bool => (self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && ! in_array($get('event_format'), [EventFormat::Online, EventFormat::Online->value], true))
                        ->afterStateUpdatedJs($this->progressUpdateJs()),

                    Select::make('location_institution_id')
                        ->native(false)
                        ->label(__('Institusi'))
                        ->options(fn (Get $get): array => $this->options->institutionOptions(
                            $this->context->submitter,
                            self::resolveSubmissionCountryId($get('submission_country_id')),
                            $this->context->scopedInstitution,
                        ))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('space_ids', []))
                        ->afterStateUpdatedJs("\$set('space_ids', [])")
                        ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution'")
                        ->required(fn (Get $get): bool => ! in_array($get('event_format'), [EventFormat::Online, EventFormat::Online->value], true) && (self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && $get('location_type') === 'institution')
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                    Select::make('location_venue_id')
                        ->native(false)
                        ->label(__('Lokasi'))
                        ->options(fn (Get $get): array => $this->options->venueOptions(
                            self::resolveSubmissionCountryId($get('submission_country_id')),
                        ))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('space_ids', []))
                        ->afterStateUpdatedJs("\$set('space_ids', [])")
                        ->visibleJs("({$hasScopedInstitutionJs} && !\$get('location_same_as_institution')) || (! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'venue')")
                        ->required(fn (Get $get): bool => ! in_array($get('event_format'), [EventFormat::Online, EventFormat::Online->value], true) && (self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && $get('location_type') === 'venue')
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->createOptionForm(VenueFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => VenueFormSchema::createOptionUsing($data, $schema)),

                    Select::make('space_ids')
                        ->native(false)
                        ->label(__('Ruang'))
                        ->helperText(__('Pilih satu atau lebih ruang (cth: Dewan Utama, Ruang Solat).'))
                        ->placeholder(__('Pilih ruang…'))
                        ->searchable()
                        ->preload()
                        ->multiple()
                        ->visibleJs("({$hasScopedInstitutionJs} && (\$get('location_same_as_institution') !== false)) || (\$get('primary_organizer_kind') === 'institution' && (\$get('location_same_as_institution') !== false)) || ((\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && (\$get('location_type') === 'institution' || (\$get('location_type') === 'venue' && \$get('location_venue_id'))))")
                        ->options(function (Get $get): array {
                            $venueId = is_scalar($get('location_venue_id')) ? trim((string) $get('location_venue_id')) : '';

                            if (
                                $venueId !== ''
                                && $get('location_type') === 'venue'
                            ) {
                                return app(SpaceEligibilityResolver::class)->venueQuery($venueId)
                                    ->where('status', 'active')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }

                            $institutionId = $get('location_institution_id');

                            if (! is_scalar($institutionId) || trim((string) $institutionId) === '') {
                                $institutionId = $this->resolvedPrimaryOrganizerInstitutionId($get('primary_organizer_id'));
                            }

                            $query = is_scalar($institutionId) && trim((string) $institutionId) !== ''
                                ? app(SpaceEligibilityResolver::class)->institutionQuery(trim((string) $institutionId))
                                : app(SpaceEligibilityResolver::class)->catalogQuery();

                            return $query
                                ->where('status', 'active')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray();
                        }),
                ]),
        ];
    }

    private function buildPersonsMediaStep(): Step
    {
        return Step::make(__('Penceramah & Media'))
            ->icon('heroicon-o-user-group')
            ->schema($this->getPersonsMediaFields());
    }

    /**
     * @return array<int, mixed>
     */
    private function getPersonsMediaFields(): array
    {
        return [
            Section::make(__('Penceramah'))
                ->schema([
                    Select::make('persons')
                        ->native(false)
                        ->label(__('Pilih Penceramah'))
                        ->placeholder(__('Pilih Penceramah'))
                        ->required(fn (Get $get): bool => self::categoriesRequirePersons($get('event_category_ids')))
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->options->personOptions($this->context->submitter))
                        ->helperText(fn (Get $get): string => self::categoriesRequirePersons($get('event_category_ids'))
                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.'))
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->personLabel($value))
                        ->getOptionLabelsUsing(fn (array $values): array => app(EntitySubmissionAccess::class)->personQueryForSubmitter($this->context->submitter)
                            ->whereIn('persons.id', array_filter($values, is_string(...)))
                            ->get()
                            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
                            ->toArray())
                        ->createOptionForm(PersonFormSchema::createOptionForm())
                        ->createOptionUsing(fn (array $data, Schema $schema): string => PersonFormSchema::createOptionUsing($data, $schema)),

                    Repeater::make('other_key_people')
                        ->label(__('Peranan Lain'))
                        ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                        ->schema([
                            Select::make('role_code')
                                ->label(__('Peranan'))
                                ->required()
                                ->options(EventKeyPersonRole::nonSpeakerOptions())
                                ->native(false),
                            Select::make('involveable_id')
                                ->native(false)
                                ->label(__('Pautkan Profil Penceramah'))
                                ->options(fn (): array => $this->options->personOptions($this->context->submitter))
                                ->searchable()
                                ->preload()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $set('display_name', null);
                                    $set('involveable_type', filled($state) ? 'person' : null);
                                })
                                ->afterStateUpdatedJs(<<<'JS'
                                                    $set('display_name', null)
                                                    $set('involveable_type', $state ? 'person' : null)
                                                    JS)
                                ->getOptionLabelUsing(fn (mixed $value): ?string => $this->personLabel($value))
                                ->createOptionForm(PersonFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, Schema $schema): string => PersonFormSchema::createOptionUsing($data, $schema)),
                            Hidden::make('involveable_type'),
                            TextInput::make('display_name')
                                ->label(__('Nama Paparan'))
                                ->maxLength(255)
                                ->extraAlpineAttributes([
                                    'x-bind:disabled' => <<<'JS'
                                        Boolean($get('involveable_id'))
                                    JS,
                                    'x-bind:required' => <<<'JS'
                                        ! Boolean($get('involveable_id'))
                                    JS,
                                ])
                                ->required(fn (Get $get): bool => blank($get('involveable_id')))
                                ->disabled(fn (Get $get): bool => filled($get('involveable_id')))
                                ->dehydrated(fn (Get $get): bool => blank($get('involveable_id')))
                                ->helperText(__('Isi nama jika tiada profil penceramah dipautkan.')),
                            Select::make('visibility')
                                ->native(false)
                                ->label(__('Keterlihatan'))
                                ->options(['public' => __('Awam'), 'private' => __('Peribadi')])
                                ->default('public')
                                ->required(),
                            Textarea::make('notes')
                                ->label(__('Nota Peranan'))
                                ->rows(2)
                                ->maxLength(500),
                        ])
                        ->default([])
                        ->afterStateUpdatedJs($this->progressUpdateJs())
                        ->addActionLabel(__('Tambah Peranan'))
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Media'))
                ->schema([
                    SpatieMediaLibraryFileUpload::make('cover')
                        ->label(__('Gambar Cover Majlis'))
                        ->collection('cover')
                        ->image()
                        ->imageEditor()
                        ->imageAspectRatio('16:9')
                        ->automaticallyOpenImageEditorForAspectRatio()
                        ->imageEditorAspectRatioOptions(['16:9', null])
                        ->automaticallyCropImagesToAspectRatio()
                        ->rules(['dimensions:ratio=16/9'])
                        ->conversion('thumb')
                        ->responsiveImages()
                        ->helperText(__('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.')),
                    SpatieMediaLibraryFileUpload::make('poster')
                        ->label(__('Poster Hebahan'))
                        ->collection('poster')
                        ->image()
                        ->imageEditor()
                        ->imageAspectRatio('3:4')
                        ->automaticallyOpenImageEditorForAspectRatio()
                        ->imageEditorAspectRatioOptions(['3:4', null])
                        ->automaticallyCropImagesToAspectRatio()
                        ->rules(['dimensions:ratio=3/4'])
                        ->conversion('poster_thumb')
                        ->responsiveImages()
                        ->helperText(__('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 3:4 dan boleh mengandungi maklumat penuh.')),
                    SpatieMediaLibraryFileUpload::make('gallery')
                        ->label(__('Galeri'))
                        ->collection('gallery')
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(10)
                        ->image()
                        ->imageEditor()
                        ->conversion('gallery_thumb')
                        ->responsiveImages()
                        ->helperText(__('Gambar tambahan untuk galeri majlis.')),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
        ];
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
                                'hasReligiousContext' => $this->isReligiousContext(
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
            ]);
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
        $countryId = self::resolveSubmissionCountryId($get('submission_country_id'));
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

    private function personLabel(mixed $value): ?string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return app(EntitySubmissionAccess::class)
            ->personQueryForSubmitter($this->context->submitter)
            ->find($value)?->formatted_name;
    }

    private function applyContextualDefaults(Get $get, Set $set): void
    {
        if (! $this->isPrayerTimeAvailable(
            $get('prayer_time'),
            $get('event_date'),
            $get('submission_country_id'),
            $get('submission_timezone'),
        )) {
            $set('prayer_time', null);
            $set('custom_time', null);
            $set('end_time', null);
        }

        if (blank($get('prayer_time'))) {
            $set('prayer_time', EventPrayerTime::LainWaktu->value);
        }

        if (self::isPrayerTime($get('prayer_time'), EventPrayerTime::LainWaktu) && blank($get('custom_time'))) {
            $set('custom_time', self::DEFAULT_SUBMISSION_TIME);
        }
    }

    private function clearCountryMismatchedEntitySelections(Get $get, Set $set): void
    {
        $countryId = self::resolveSubmissionCountryId($get('submission_country_id'));

        if ($countryId === null) {
            return;
        }

        $access = app(EntitySubmissionAccess::class);

        if (! $this->context->scopedInstitution instanceof Institution) {
            $organizerInstitutionId = $this->normalizeNullableUuid($get('primary_organizer_institution_id'));

            if ($organizerInstitutionId !== null && ! $access->institutionBelongsToCountry($organizerInstitutionId, $countryId)) {
                $set('primary_organizer_institution_id', null);
                $set('primary_organizer_id', null);
            }

            $locationInstitutionId = $this->normalizeNullableUuid($get('location_institution_id'));

            if ($locationInstitutionId !== null && ! $access->institutionBelongsToCountry($locationInstitutionId, $countryId)) {
                $set('location_institution_id', null);
                $set('space_ids', []);
            }
        }

        $locationVenueId = $this->normalizeNullableUuid($get('location_venue_id'));

        if ($locationVenueId !== null && ! $access->venueBelongsToCountry($locationVenueId, $countryId)) {
            $set('location_venue_id', null);
            $set('space_ids', []);
        }
    }

    private function normalizeNullableUuid(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && Str::isUuid($value) ? $value : null;
    }

    private function isPrayerTimeAvailable(mixed $value, mixed $eventDate, mixed $countryId, mixed $submittedTimezone = null): bool
    {
        $policy = app(SubmissionTimingPolicy::class);

        return $policy->isPrayerTimeAvailable(
            $value,
            $eventDate,
            self::resolveSubmissionTimezone($countryId, $submittedTimezone),
            $policy->countryIso2ForId($countryId),
        );
    }

    private function syncSubmissionTimezoneForCountry(Get $get, Set $set): void
    {
        $countryId = app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($get('submission_country_id'));
        $timezones = app(SubmissionTimingPolicy::class)->countryTimezones($countryId);

        if (count($timezones) === 1) {
            $set('submission_timezone', $timezones[0]);

            return;
        }

        $set('submission_timezone', null);
    }

    private function isReligiousContext(mixed $topicIds): bool
    {
        return $this->hasAgamaKerohanianTopic($topicIds);
    }

    private function hasAgamaKerohanianTopic(mixed $topicIds): bool
    {
        return in_array(
            self::AGAMA_KEROHANIAN_CODE,
            $this->selectedTaxonomyCodes($topicIds, EventTaxonomyCode::Domain->value),
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function selectedTaxonomyCodes(mixed $state, string $taxonomyCode): array
    {
        $ids = $this->selectedIds($state);

        if ($ids === []) {
            return [];
        }

        $taxonomyId = EventTaxonomy::query()->where('code', $taxonomyCode)->value('id');

        if (! is_string($taxonomyId)) {
            return [];
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->whereIn('id', $ids)
            ->pluck('code')
            ->map(strval(...))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function selectedIds(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->filter(fn (mixed $value): bool => is_scalar($value) && Str::isUuid((string) $value))
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    private function resolvedPrimaryOrganizerInstitutionId(mixed $primaryOrganizerId): ?string
    {
        return self::resolvedPrimaryOrganizerType($primaryOrganizerId) === 'institution'
            ? (is_scalar($primaryOrganizerId) && trim((string) $primaryOrganizerId) !== '' ? trim((string) $primaryOrganizerId) : null)
            : null;
    }

    /**
     * @param  array<string, string|null>|null  $previewStarts
     */
    /**
     * @param  array<string, string|null>|null  $previewStarts
     * @param  array<string, mixed>|null  $formState
     */
    private function resolveStartTimeForComparison(mixed $prayerTimeValue, mixed $customTime, ?array $previewStarts = null, ?array $formState = null): ?string
    {
        return app(SubmissionTimingPolicy::class)->resolveStartTimeForComparison($prayerTimeValue, $customTime, $previewStarts, $formState);
    }

    /**
     * Current form state for a fresh cache-only comparison. The hidden
     * hint is a snapshot that deferred warming can supersede; the rule
     * re-resolves from live state so it never rejects what submit accepts.
     *
     * @return array<string, mixed>
     */
    private function comparisonFormState(Get $get): array
    {
        return [
            'event_date' => $get('event_date'),
            'submission_country_id' => $get('submission_country_id'),
            'submission_timezone' => $get('submission_timezone'),
            'event_format' => $get('event_format'),
            'location_type' => $get('location_type'),
            'location_institution_id' => $get('location_institution_id'),
            'location_venue_id' => $get('location_venue_id'),
            'location_same_as_institution' => $get('location_same_as_institution'),
            'primary_organizer_id' => $get('primary_organizer_id'),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function previewStartsForComparison(mixed $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $decoded = json_decode($state, true);

        if (! is_array($decoded) || ! isset($decoded['starts']) || ! is_array($decoded['starts'])) {
            return null;
        }

        return $decoded['starts'];
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeAgeGroupState(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->map(function (mixed $ageGroup): ?string {
                if ($ageGroup instanceof EventAgeGroup) {
                    return $ageGroup->value;
                }

                if (is_string($ageGroup) && filled($ageGroup)) {
                    return $ageGroup;
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $ageGroups
     * @return array<int, string>
     */
    public static function normalizeAgeGroupSelection(array $ageGroups): array
    {
        $ageGroups = array_values(array_unique($ageGroups));
        $specificAgeGroups = [
            EventAgeGroup::Adults->value,
            EventAgeGroup::Youth->value,
            EventAgeGroup::Children->value,
            EventAgeGroup::Seniors->value,
        ];

        if (in_array(EventAgeGroup::AllAges->value, $ageGroups, true)) {
            return [EventAgeGroup::AllAges->value];
        }

        if (count(array_intersect($specificAgeGroups, $ageGroups)) === count($specificAgeGroups)) {
            return [EventAgeGroup::AllAges->value];
        }

        return $ageGroups;
    }

    public static function hasCommunityCategorySelection(mixed $categoryIds): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        return app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery(
            app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
        );
    }

    public static function categoriesRequirePersons(mixed $categoryIds): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
        );
    }

    public static function isPrayerTime(mixed $value, EventPrayerTime $expected): bool
    {
        return $value instanceof EventPrayerTime
            ? $value === $expected
            : $value === $expected->value;
    }

    public static function selectedPrimaryOrganizerKind(mixed $organizerKind, mixed $primaryOrganizerId): ?string
    {
        if (in_array($organizerKind, ['institution', 'person'], true)) {
            return $organizerKind;
        }

        return self::resolvedPrimaryOrganizerType($primaryOrganizerId);
    }

    public static function resolveSubmissionTimezone(mixed $countryId = null, mixed $submittedTimezone = null): string
    {
        $resolvedCountryId = app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($countryId);

        return app(SubmissionTimingPolicy::class)->previewSubmissionTimezone($resolvedCountryId, $submittedTimezone);
    }

    /**
     * @return array<string, string>
     */
    public static function submissionTimezoneOptions(mixed $countryId = null): array
    {
        $resolvedCountryId = app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($countryId);
        $timezones = app(SubmissionTimingPolicy::class)->countryTimezones($resolvedCountryId);

        return array_combine($timezones, $timezones) ?: [];
    }

    public static function isSubmissionTimezoneRequired(mixed $countryId = null): bool
    {
        $resolvedCountryId = app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($countryId);

        return app(SubmissionTimingPolicy::class)->submissionTimezoneRequired($resolvedCountryId);
    }

    /**
     * Return one completion check for every required field currently relevant
     * to the form state. Conditional fields are deliberately added only when
     * their own validation rule is active.
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
            self::hasSelection($categoryIds),
            self::hasSelection($topicIds),
            filled($state['title'] ?? null),
            filled($state['submission_country_id'] ?? null),
            filled($state['event_date'] ?? null),
            filled($state['event_format'] ?? null),
            filled($state['visibility'] ?? null),
            filled($state['gender'] ?? null),
            self::hasSelection($state['age_group'] ?? []),
            self::hasSelection($state['languages'] ?? []),
        ];

        $requiredFields[] = filled($prayerTime);

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

        if (self::hasSelection($categoryIds) && self::categoriesRequirePersons($categoryIds)) {
            $requiredFields[] = self::hasSelection($state['persons'] ?? []);
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

    private static function resolvedPrimaryOrganizerType(mixed $primaryOrganizerId): ?string
    {
        if (! is_scalar($primaryOrganizerId)) {
            return null;
        }

        $organizerId = trim((string) $primaryOrganizerId);

        if ($organizerId === '') {
            return null;
        }

        if (Institution::query()->whereKey($organizerId)->exists()) {
            return 'institution';
        }

        if (Person::query()->whereKey($organizerId)->exists()) {
            return 'person';
        }

        return null;
    }

    private static function resolveSubmissionCountryId(mixed $countryId = null): ?string
    {
        return app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId($countryId, 'MY');
    }

    private static function hasSelection(mixed $value): bool
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return collect($value)->contains(fn (mixed $item): bool => filled($item));
        }

        return filled($value);
    }

    /**
     * @return list<string>
     */
    public static function normalizeEventCategoryState(mixed $state): array
    {
        return self::normalizeSingleSelectList($state);
    }

    /**
     * @return list<string>
     */
    public static function normalizeDomainTagState(mixed $state): array
    {
        return self::normalizeSingleSelectList($state);
    }

    /**
     * Single-select UI canonical (scalar) to backend list canonical. Scalars
     * wrap, lists pass through, blanks and non-scalars drop so malformed
     * input still reaches the validator as an empty or invalid list and is
     * rejected there instead of fataling here.
     *
     * @return list<string>
     */
    private static function normalizeSingleSelectList(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }
}
