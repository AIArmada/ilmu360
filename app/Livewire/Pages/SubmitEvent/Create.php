<?php

namespace App\Livewire\Pages\SubmitEvent;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\FilamentEvents\Resources\EventResource;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Forms\Components\Select;
use App\Forms\InstitutionFormSchema;
use App\Forms\PersonFormSchema;
use App\Forms\SharedFormSchema;
use App\Forms\VenueFormSchema;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ai\EventMediaExtractionService;
use App\Services\Captcha\TurnstileVerifier;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Submission\EntitySubmissionAccess;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
class Create extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    private const string REVIEW_STEP_ID = 'form.semak-sebelum-hantar::data::wizard-step';

    public function render(): View
    {
        return view('components.pages.submit-event.create');
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[Url(as: 'step')]
    public ?string $wizardStep = null;

    public ?string $eventId = null;

    public ?string $duplicateEventId = null;

    public ?string $scopedInstitutionId = null;

    public ?TemporaryUploadedFile $event_source_attachment = null;

    protected ?Institution $resolvedScopedInstitution = null;

    protected function eventForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Submit event form is not available.');
    }

    public function mount(): void
    {
        $this->eventId = request()->query('event');
        $this->duplicateEventId = request()->query('duplicate');
        $scopedInstitution = $this->resolveScopedInstitution(request()->query('institution'));

        if ($scopedInstitution instanceof Institution) {
            $this->scopedInstitutionId = $scopedInstitution->id;
            $this->resolvedScopedInstitution = $scopedInstitution;
        }

        $state = [
            'submitter_name' => auth()->user()?->name,
            'submitter_email' => auth()->user()?->email,
            'children_allowed' => true,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges],
            'languages' => [Language::where('code', 'ms')->value('id') ?? ''],
            'event_format' => EventFormat::Physical,
            'visibility' => EventVisibility::Public->value,
            'location_same_as_institution' => true,
            'location_type' => 'institution',
            'is_muslim_only' => false,
            'other_key_people' => [],
            'captcha_token' => null,
            'submission_country_id' => $this->defaultSubmissionCountryId(),
        ];

        if (($eventContainer = $this->selectedEventContainer()) instanceof Event) {
            $state = array_replace($state, $this->eventContainerDefaults($eventContainer));
        }

        if (($duplicateEvent = $this->selectedDuplicateEvent()) instanceof Event) {
            $state = array_replace($state, $this->duplicateEventDefaults($duplicateEvent));
        }

        if ($scopedInstitution instanceof Institution) {
            $state = array_replace($state, $this->scopedInstitutionDefaults($scopedInstitution));
        }

        $this->eventForm()->fill($state);
    }

    protected function resolveScopedInstitution(mixed $institutionId): ?Institution
    {
        if (! is_string($institutionId) || ! Str::isUuid($institutionId)) {
            return null;
        }

        $user = $this->submitterUser();

        abort_unless($user instanceof User, 403);

        $institution = app(EntitySubmissionAccess::class)
            ->memberInstitutionQueryForSubmitter($user)
            ->whereKey($institutionId)
            ->first();

        abort_unless($institution instanceof Institution, 403);

        return $institution;
    }

    protected function scopedInstitution(): ?Institution
    {
        if ($this->resolvedScopedInstitution instanceof Institution) {
            return $this->resolvedScopedInstitution;
        }

        $institutionId = $this->scopedInstitutionId;

        if (! is_string($institutionId) || ! Str::isUuid($institutionId)) {
            return null;
        }

        $institution = Institution::query()->find($institutionId);

        if (! $institution instanceof Institution) {
            return null;
        }

        $this->resolvedScopedInstitution = $institution;

        return $institution;
    }

    protected function hasScopedInstitution(): bool
    {
        return $this->scopedInstitution() instanceof Institution;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopedInstitutionDefaults(Institution $institution): array
    {
        return [
            'primary_organizer_kind' => 'institution',
            'primary_organizer_id' => $institution->id,
            'primary_organizer_institution_id' => $institution->id,
            'primary_organizer_person_id' => null,
            'location_same_as_institution' => true,
            'location_type' => 'institution',
            'location_institution_id' => $institution->id,
            'location_venue_id' => null,
            'space_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyLocationPickerSelection(
        string $statePath,
        array $selection,
        ResolveGooglePlaceSelectionAction $resolveGooglePlaceSelectionAction,
    ): array {
        $currentAddress = data_get($this, $statePath);
        $currentAddress = is_array($currentAddress) ? $currentAddress : [];
        $resolvedAddress = $resolveGooglePlaceSelectionAction->handle(array_merge($selection, [
            'fallbackCountryId' => $currentAddress['country_id'] ?? null,
        ]));

        data_set($this, $statePath, array_merge($currentAddress, $resolvedAddress, [
            'cascade_reset_guard' => SharedFormSchema::publicLocationPickerCascadeResetGuard(),
        ]));

        return $resolvedAddress;
    }

    protected function submitCacheKey(string $key): string
    {
        return "{$key}_safe_v1";
    }

    /**
     * @return array<int|string, string>
     */
    protected function cachedSubmitLanguageOptions(): array
    {
        $preferredOrder = ['ms', 'ar', 'en', 'id', 'zh', 'ta', 'jv'];
        $preferredLabels = [
            'ms' => 'Bahasa Melayu',
            'ar' => 'Bahasa Arab',
            'en' => 'Bahasa Inggeris',
            'id' => 'Bahasa Indonesia',
            'zh' => 'Bahasa Cina',
            'ta' => 'Bahasa Tamil',
            'jv' => 'Bahasa Jawa',
        ];

        return app(SelectionCatalogCache::class)->languageOptionsForCodes($preferredOrder, $preferredLabels);
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    protected function cachedSubmitTagOptions(EventTaxonomyCode $type, string $cachePrefix, array $statuses): array
    {
        return Cache::remember($this->submitCacheKey($cachePrefix.'_'.app()->getLocale()), 60, function () use ($type): array {
            $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->value('id');

            if ($taxonomyId === null) {
                return [];
            }

            return EventTerm::query()
                ->where('event_taxonomy_id', $taxonomyId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (EventTerm $term): array => [(string) $term->id => (string) $term->name])
                ->all();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function cachedSubmitVenueOptions(): array
    {
        return Cache::remember($this->submitCacheKey('submit_venues'), 60, fn (): array => Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereIn('status', ['verified', 'pending'])
            ->pluck('name', 'id')
            ->all());
    }

    public function extractEventFromMedia(EventMediaExtractionService $eventMediaExtractionService): void
    {
        $maxFileSizeKb = (int) config('ai.features.event_media_extraction.max_file_size_kb', 10240);
        $acceptedMimeTypes = config('ai.features.event_media_extraction.accepted_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        if (! is_array($acceptedMimeTypes) || $acceptedMimeTypes === []) {
            $acceptedMimeTypes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ];
        }

        $validated = Validator::make(
            ['event_source_attachment' => $this->event_source_attachment],
            [
                'event_source_attachment' => [
                    'required',
                    'file',
                    "max:{$maxFileSizeKb}",
                    'mimetypes:'.implode(',', $acceptedMimeTypes),
                ],
            ],
            [
                'event_source_attachment.required' => __('Sila muat naik poster, gambar, atau PDF terlebih dahulu.'),
                'event_source_attachment.file' => __('Fail yang dimuat naik tidak sah.'),
                'event_source_attachment.max' => __('Saiz fail melebihi had yang dibenarkan.'),
                'event_source_attachment.mimetypes' => __('Hanya fail PDF, JPEG, PNG, atau WEBP dibenarkan.'),
            ],
        )->validate();

        /** @var TemporaryUploadedFile $file */
        $file = $validated['event_source_attachment'];

        try {
            $extractedState = $eventMediaExtractionService->extract($file);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('Pengekstrakan AI gagal. Sila cuba semula.'))
                ->danger()
                ->send();

            return;
        }

        $mergedState = array_replace($this->data ?? [], $extractedState);
        $mergedState['age_group'] = $this->normalizeAgeGroupState($mergedState['age_group'] ?? []);

        if (
            in_array(EventAgeGroup::Children->value, $mergedState['age_group'], true) ||
            in_array(EventAgeGroup::AllAges->value, $mergedState['age_group'], true)
        ) {
            $mergedState['children_allowed'] = true;
        }

        $mimeType = (string) $file->getMimeType();

        if (str_starts_with($mimeType, 'image/')) {
            $sourceRatio = $this->uploadedImageAspectRatio($file);

            if ($sourceRatio === '3:4' && blank($mergedState['poster'] ?? null)) {
                $mergedState['poster'] = $file;
            }

            if ($sourceRatio === '16:9' && blank($mergedState['cover'] ?? null)) {
                $mergedState['cover'] = $file;
            }
        }

        $this->eventForm()->fill($mergedState);
        $this->data = $mergedState;

        $wizard = $this->eventForm()->getComponent(
            fn (mixed $component): bool => $component instanceof Wizard
        );

        if ($wizard instanceof Wizard) {
            $steps = array_values($wizard->getChildSchema()->getComponents());
            $lastStep = end($steps);

            if ($lastStep instanceof Step && filled($lastStep->getKey())) {
                $wizard->goToStep($lastStep->getKey());
            }
        }

        Notification::make()
            ->title(__('Maklumat majlis berjaya diekstrak dengan AI.'))
            ->success()
            ->send();
    }

    private function uploadedImageAspectRatio(TemporaryUploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === '') {
            return null;
        }

        $dimensions = @getimagesize($path);

        if (! is_array($dimensions)) {
            return null;
        }

        $width = $dimensions[0];
        $height = $dimensions[1];

        return match (true) {
            $width > 0 && $height > 0 && abs(($width / $height) - (16 / 9)) < 0.01 => '16:9',
            $width > 0 && $height > 0 && abs(($width / $height) - (3 / 4)) < 0.01 => '3:4',
            default => null,
        };
    }

    public function form(Schema $schema): Schema
    {
        $hasScopedInstitution = $this->hasScopedInstitution();
        $hasScopedInstitutionJs = $hasScopedInstitution ? 'true' : 'false';
        $isReviewStep = $this->wizardStep === self::REVIEW_STEP_ID;
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
            $this->buildCategoriesStep(),
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
        return Step::make(__('Maklumat Majlis'))
            ->icon('heroicon-o-document-text')
            ->schema($this->getEventInfoFields());
    }

    /**
     * @return array<int, mixed>
     */
    private function getEventInfoFields(): array
    {
        return [
            Select::make('event_category_ids')
                ->label(__('Jenis Majlis'))
                ->required()
                ->multiple()
                ->closeOnSelect()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if ($this->hasCommunityCategorySelection($state)) {
                        $set('event_format', EventFormat::Physical->value);
                    }
                })
                ->options(fn (): array => app(EventCategoryCatalog::class)->options())
                ->searchable(),

            Select::make('title')
                ->label(__('Tajuk Majlis'))
                ->required()
                ->searchable()
                ->allowHtml()
                ->live()
                ->getSearchResultsUsing(function (string $search): array {
                    if ($search === '' || $search === '0') {
                        return [];
                    }

                    $results = Event::query()
                        ->whereLike('title', "%{$search}%")
                        ->where('status', 'approved')
                        ->limit(10)
                        ->pluck('title', 'title')
                        ->toArray();

                    $exactMatch = collect($results)->contains(fn ($value) => mb_strtolower($value) === mb_strtolower($search));

                    if (! $exactMatch) {
                        $results = ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".__('Tambah')." '{$search}'</span>"] + $results;
                    }

                    return $results;
                })
                ->getOptionLabelUsing(function ($value): ?string {
                    if (str_starts_with($value, '__quick_add__')) {
                        return substr($value, strlen('__quick_add__'));
                    }

                    return $value;
                })
                ->afterStateUpdatedJs(<<<'JS'
                                    if ($state && $state.startsWith('__quick_add__')) {
                                        $set('title', $state.substring('__quick_add__'.length))
                                    }
                                JS)
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if (! $state || str_starts_with($state, '__quick_add__')) {
                        return;
                    }

                    $existingEvent = Event::query()
                        ->where('title', $state)
                        ->where('status', 'approved')
                        ->with(['classifications', 'references'])
                        ->latest()
                        ->first();

                    if (! $existingEvent) {
                        return;
                    }

                    $termsByTaxonomy = $existingEvent->classifications->groupBy('taxonomy_code');

                    $set('event_category_ids', $termsByTaxonomy->get('event_category', collect())->pluck('event_term_id')->filter()->values()->all());

                    if ($termsByTaxonomy->has(EventTaxonomyCode::Domain->value)) {
                        $set('domain_tags', $termsByTaxonomy->get(EventTaxonomyCode::Domain->value)->pluck('event_term_id')->filter()->values()->all());
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
                                ->pluck('referenceable_id')
                                ->filter()
                                ->values()
                                ->all(),
                        );
                    }
                })
                ->placeholder(__('Cari atau masukkan tajuk majlis...')),

            RichEditor::make('description')
                ->label(__('Keterangan'))
                ->maxLength(5000)
                ->disableToolbarButtons(['table'])
                ->floatingToolbars([])
                ->placeholder(__('Terangkan mengenai majlis, topik yang akan dikupas, dll.')),

            Grid::make(['default' => 1, 'sm' => 2, 'md' => 8])
                ->schema([
                    Select::make('submission_country_id')
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
                        ->afterStateUpdatedJs(<<<'JS'
                            $set('prayer_time', null)
                            $set('custom_time', null)
                            $set('end_time', null)
                        JS)
                        ->columnSpan(['default' => 1, 'md' => 2]),

                    DatePicker::make('event_date')
                        ->label(__('Tarikh'))
                        ->required()
                        ->native()
                        ->minDate(now()->startOfDay())
                        ->live()
                        ->afterStateUpdatedJs(<<<'JS'
                                                    $set('prayer_time', null)
                                                JS)
                        ->columnSpan(['default' => 1, 'md' => 2]),

                    Select::make('prayer_time')
                        ->label(__('Waktu'))
                        ->required()
                        ->afterStateUpdatedJs(<<<'JS'
                                    if ($state !== 'lain_waktu') {
                                        $set('custom_time', null)
                                    }
                                JS)
                        ->options(function (Get $get): array {
                            $eventDate = $get('event_date');

                            return collect(EventPrayerTime::cases())
                                ->filter(function (EventPrayerTime $case) use ($eventDate, $get) {
                                    if (! $eventDate) {
                                        return ! in_array($case, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat, EventPrayerTime::SebelumMaghrib, EventPrayerTime::SelepasTarawih], true);
                                    }

                                    $timezone = $this->resolveSubmissionTimezone($get('submission_country_id'));
                                    $date = Carbon::parse($eventDate, $timezone)->startOfDay();

                                    if ($case === EventPrayerTime::SebelumJumaat) {
                                        return $date->isFriday();
                                    }

                                    if ($case === EventPrayerTime::SelepasJumaat) {
                                        return $date->isFriday();
                                    }

                                    if ($case === EventPrayerTime::SebelumMaghrib) {
                                        return $this->isRamadhan($date, $timezone);
                                    }

                                    if ($case === EventPrayerTime::SelepasTarawih) {
                                        return $this->isRamadhan($date, $timezone);
                                    }

                                    return true;
                                })
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
                        ->afterStateUpdatedJs(<<<'JS'
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
                                                .title(@js(__('Masa akhir mestilah selepas masa mula.')))
                                                .warning()
                                                .send();
                                        }
                                    }
                                JS)
                        ->visibleJs(<<<'JS'
                                    $get('prayer_time') === 'lain_waktu'
                                    JS)
                        ->requiredIf('prayer_time', EventPrayerTime::LainWaktu)
                        ->markAsRequired()
                        ->columnSpan(['default' => 1, 'md' => 2])
                        ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $eventDate = $get('event_date');
                            $timezone = $this->resolveSubmissionTimezone($get('submission_country_id'));
                            $now = Carbon::now($timezone);

                            if (! $eventDate || ! $value) {
                                return;
                            }

                            $eventDay = Carbon::parse($eventDate, $timezone)->startOfDay();

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
                        ->afterStateUpdatedJs(<<<'JS'
                                    const customTime = $get('custom_time');
                                    const endTime = $state;
                                    const prayerTime = $get('prayer_time');
                                    const estimatedStartByPrayer = {
                                        selepas_subuh: '06:30',
                                        selepas_zuhur: '13:30',
                                        sebelum_jumaat: '13:45',
                                        selepas_jumaat: '14:00',
                                        selepas_asar: '17:00',
                                        sebelum_maghrib: '19:45',
                                        selepas_maghrib: '20:00',
                                        selepas_isyak: '21:30',
                                        selepas_tarawih: '22:30',
                                    };
                                    
                                    const guessedStartTime = prayerTime === 'lain_waktu'
                                        ? customTime
                                        : (estimatedStartByPrayer[prayerTime] ?? null);
                                    
                                    if (guessedStartTime && endTime) {
                                        const startParts = guessedStartTime.split(':');
                                        const endParts = endTime.split(':');
                                        
                                        const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1] || 0);
                                        const endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1] || 0);
                                        
                                        if (endMinutes <= startMinutes) {
                                            $set('end_time', null);
                                            new FilamentNotification()
                                                .title(@js(__('Masa akhir mestilah selepas masa mula.')))
                                                .warning()
                                                .send();
                                        }
                                    }
                                JS)
                        ->columnSpan(['default' => 1, 'md' => 2])
                        ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            if (! $value) {
                                return;
                            }

                            $prayerTimeRaw = $get('prayer_time');
                            $startTime = $this->resolveStartTimeForComparison(
                                $prayerTimeRaw,
                                $get('custom_time')
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
                ]),

            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Radio::make('event_format')
                        ->label(__('Format Majlis'))
                        ->required()
                        ->options(EventFormat::class)
                        ->default(EventFormat::Physical)
                        ->disableOptionWhen(
                            fn (string $value, Get $get): bool => $this->hasCommunityCategorySelection($get('event_category_ids'))
                            && $value !== EventFormat::Physical->value
                        )
                        ->inline(),

                    Radio::make('visibility')
                        ->label(__('Keterlihatan'))
                        ->required()
                        ->options(EventVisibility::class)
                        ->default(EventVisibility::Public)
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

            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('gender')
                        ->label(__('Jantina'))
                        ->required()
                        ->options(EventGenderRestriction::class)
                        ->default(EventGenderRestriction::All),

                    Select::make('age_group')
                        ->label(__('Peringkat Umur'))
                        ->required()
                        ->options(EventAgeGroup::class)
                        ->closeOnSelect()
                        ->multiple()
                        ->live()
                        ->afterStateUpdatedJs(<<<'JS'
                                                    const ageGroups = $state || []
                                                    if (ageGroups.includes('all_ages') && ageGroups.length > 1) {
                                                        $set('age_group', ageGroups.filter((group) => group !== 'all_ages'))
                                                        return
                                                    }
                                                    if (ageGroups.includes('children') || ageGroups.includes('all_ages')) {
                                                        $set('children_allowed', true)
                                                    }
                                                    JS)
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $ageGroups = $this->normalizeAgeGroupState($state);

                            if (in_array(EventAgeGroup::AllAges->value, $ageGroups, true) && count($ageGroups) > 1) {
                                $ageGroups = array_values(array_filter(
                                    $ageGroups,
                                    fn (string $ageGroup): bool => $ageGroup !== EventAgeGroup::AllAges->value
                                ));
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
                        ->label(__('Bahasa'))
                        ->helperText(__('Bahasa yang akan digunakan dalam majlis.'))
                        ->placeholder(__('Pilih bahasa…'))
                        ->closeOnSelect()
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->preload()
                        ->default([101])
                        ->options(fn (): array => $this->cachedSubmitLanguageOptions()),

                    Toggle::make('children_allowed')
                        ->label(__('Kanak-kanak Dibenarkan'))
                        ->helperText(__('Adakah ibu bapa boleh membawa anak kecil ke majlis ini?'))
                        ->default(true)
                        ->inline(false)
                        ->disabled(function (Get $get): bool {
                            $ageGroups = $this->normalizeAgeGroupState($get('age_group'));

                            return in_array(EventAgeGroup::Children->value, $ageGroups, true) ||
                                in_array(EventAgeGroup::AllAges->value, $ageGroups, true);
                        })
                        ->dehydrated(),

                    Toggle::make('is_muslim_only')
                        ->label(__('Terbuka untuk Muslim Sahaja'))
                        ->helperText(__('Pilih jika majlis ini hanya terbuka untuk penganut agama Islam.'))
                        ->inline(false)
                        ->default(false),
                ]),
        ];
    }

    private function buildCategoriesStep(): Step
    {
        return Step::make(__('Kategori & Bidang'))
            ->icon('heroicon-o-tag')
            ->schema($this->getCategoryFields());
    }

    /**
     * @return array<int, mixed>
     */
    private function getCategoryFields(): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('domain_tags')
                        ->label(__('Kategori'))
                        ->helperText(__('Pilih kategori ceramah utama. Boleh pilih lebih daripada satu.'))
                        ->closeOnSelect()
                        ->placeholder(__('Pilih kategori…'))
                        ->multiple()
                        ->searchable(false)
                        ->preload()
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
                        ->options(fn (): array => $this->cachedSubmitTagOptions(
                            type: EventTaxonomyCode::Domain,
                            cachePrefix: 'submit_tags_domain',
                            statuses: ['verified', 'pending'],
                        ))
                        ->rules(['max:3'])
                        ->validationMessages([
                            'max' => __('Maksimum 3 kategori sahaja.'),
                        ]),

                    Select::make('discipline_tags')
                        ->label(__('Bidang Ilmu'))
                        ->helperText(__('Pilih bidang yang menggambarkan isi ceramah.'))
                        ->placeholder(__('Pilih atau taip untuk tambah bidang…'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->allowHtml()
                        ->options(fn (): array => $this->cachedSubmitTagOptions(
                            type: EventTaxonomyCode::Discipline,
                            cachePrefix: 'submit_tags_discipline_verified',
                            statuses: ['verified'],
                        ))
                        ->getSearchResultsUsing(function (string $search): array {
                            if (blank($search)) {
                                return [];
                            }

                            $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Discipline->value)->value('id');
                            $results = EventTerm::query()
                                ->where('event_taxonomy_id', $taxonomyId)
                                ->where('is_active', true)
                                ->whereLike('name', "%{$search}%")
                                ->orderBy('sort_order')
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->toArray();

                            return ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".__('Tambah')." '{$search}'</span>"] + $results;
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

                            return $labels;
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

            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('source_tags')
                        ->closeOnSelect()
                        ->label(__('Sumber Utama'))
                        ->helperText(__('Pilih sumber rujukan utama (jika ada).'))
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
                        ->options(fn (): array => $this->cachedSubmitTagOptions(
                            type: EventTaxonomyCode::Source,
                            cachePrefix: 'submit_tags_source',
                            statuses: ['verified', 'pending'],
                        )),

                    Select::make('issue_tags')
                        ->label(__('Tema / Isu'))
                        ->helperText(__('Pilih tema supaya mudah dicari.'))
                        ->placeholder(__('Pilih atau taip untuk tambah tema…'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->allowHtml()
                        ->options(fn (): array => $this->cachedSubmitTagOptions(
                            type: EventTaxonomyCode::Issue,
                            cachePrefix: 'submit_tags_issue_verified',
                            statuses: ['verified'],
                        ))
                        ->getSearchResultsUsing(function (string $search): array {
                            if (blank($search)) {
                                return [];
                            }

                            $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Issue->value)->value('id');
                            $results = EventTerm::query()
                                ->where('event_taxonomy_id', $taxonomyId)
                                ->where('is_active', true)
                                ->whereLike('name', "%{$search}%")
                                ->orderBy('sort_order')
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->toArray();

                            return ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".__('Tambah')." '{$search}'</span>"] + $results;
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

                            return $labels;
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
                ->label(__('Rujukan Kitab'))
                ->helperText(__('Pilih kitab atau buku rujukan yang digunakan (jika ada).'))
                ->placeholder(__('Cari atau pilih rujukan…'))
                ->multiple()
                ->closeOnSelect()
                ->searchable()
                ->preload()
                ->native(false)
                ->relationship('references', 'title', fn (Builder $query) => $query->whereIn('status', ['verified', 'pending']))
                ->createOptionForm([
                    TextInput::make('title')
                        ->label(__('Tajuk Kitab / Buku'))
                        ->required()
                        ->maxLength(255)
                        ->placeholder(__('cth: Riyadhus Solihin, Ihya Ulumiddin')),
                    TextInput::make('author')
                        ->label(__('Pengarang'))
                        ->maxLength(255)
                        ->placeholder(__('cth: Imam Nawawi, Imam Ghazali')),
                    Select::make('type')
                        ->label(__('Jenis'))
                        ->options(ReferenceType::class)
                        ->default(ReferenceType::Book->value),
                    TextInput::make('publication_year')
                        ->label(__('Tahun Terbitan'))
                        ->numeric()
                        ->minValue(1000)
                        ->maxValue((int) now()->addYears(1)->format('Y'))
                        ->placeholder(__('cth: 2018')),
                    TextInput::make('publisher')
                        ->label(__('Penerbit'))
                        ->maxLength(255)
                        ->placeholder(__('cth: Dar al-Kutub')),
                    TextInput::make('reference_url')
                        ->label(__('Pautan Rujukan'))
                        ->url()
                        ->maxLength(255)
                        ->placeholder(__('https://...')),
                    SpatieMediaLibraryFileUpload::make('front_cover')
                        ->label(__('Muka Depan'))
                        ->collection('front_cover')
                        ->image()
                        ->imageEditor()
                        ->conversion('thumb')
                        ->responsiveImages(),
                    SpatieMediaLibraryFileUpload::make('back_cover')
                        ->label(__('Muka Belakang'))
                        ->collection('back_cover')
                        ->image()
                        ->imageEditor()
                        ->conversion('thumb')
                        ->responsiveImages(),
                    SpatieMediaLibraryFileUpload::make('gallery')
                        ->label(__('Galeri'))
                        ->collection('gallery')
                        ->multiple()
                        ->image()
                        ->imageEditor()
                        ->conversion('gallery_thumb')
                        ->responsiveImages()
                        ->maxFiles(5)
                        ->helperText(__('Sehingga 5 gambar tambahan')),
                    Textarea::make('description')
                        ->label(__('Keterangan Ringkas'))
                        ->rows(3)
                        ->placeholder(__('Nota ringkas tentang rujukan ini…'))
                        ->columnSpanFull(),
                ])
                ->createOptionUsing(function (array $data, Schema $schema): string {
                    $reference = Reference::create([
                        'title' => $data['title'],
                        'slug' => app(GenerateReferenceSlugAction::class)->handle((string) ($data['title'] ?? '')),
                        'author' => $data['author'] ?? null,
                        'type' => $data['type'] ?? ReferenceType::Book->value,
                        'year' => filled($data['publication_year'] ?? null) ? (string) $data['publication_year'] : null,
                        'publisher' => $data['publisher'] ?? null,
                        'description' => $data['description'] ?? null,
                        'is_canonical' => false,
                        'status' => 'active',
                    ]);

                    $schema->model($reference)->saveRelationships();

                    if (! empty($data['reference_url'])) {
                        $reference->socialProfiles()->create([
                            'platform' => 'website',
                            'url' => $data['reference_url'],
                        ]);
                    }

                    return (string) $reference->getKey();
                }),
        ];
    }

    private function buildOrganizerLocationStep(bool $hasScopedInstitution, string $hasScopedInstitutionJs): Step
    {
        return Step::make(__('Penganjur & Lokasi'))
            ->icon('heroicon-o-building-office')
            ->schema($this->getOrganizerLocationFields($hasScopedInstitution, $hasScopedInstitutionJs));
    }

    /**
     * @return array<int, mixed>
     */
    private function getOrganizerLocationFields(bool $hasScopedInstitution, string $hasScopedInstitutionJs): array
    {
        return [
            Section::make(__('Penganjur'))
                ->schema([
                    Hidden::make('primary_organizer_id'),

                    Radio::make('primary_organizer_kind')
                        ->label(__('Jenis Penganjur'))
                        ->required(fn (Get $get): bool => ! $hasScopedInstitution && ! filled($get('primary_organizer_id')))
                        ->options([
                            'institution' => __('Institusi'),
                            'person' => __('Penceramah'),

                        ])
                        ->default('institution')
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
                        }),

                    Select::make('primary_organizer_institution_id')
                        ->label(__('Institusi'))
                        ->options(fn (): array => $this->availableInstitutionOptions())
                        ->searchable()
                        ->preload()
                        ->disabled($hasScopedInstitution)
                        ->dehydrated()
                        ->visibleJs($hasScopedInstitutionJs." || \$get('primary_organizer_kind') === 'institution'")
                        ->required(fn (Get $get): bool => $this->selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'institution' && ! filled($get('primary_organizer_id')))
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            $organizerId = is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null;

                            $set('primary_organizer_id', $organizerId);

                            if ((bool) $get('location_same_as_institution')) {
                                $set('location_institution_id', $organizerId);
                                $set('location_venue_id', null);
                            }
                        })
                        ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                    Select::make('primary_organizer_person_id')
                        ->label(__('Penceramah'))
                        ->options(fn (): array => $this->availablePersonOptions())
                        ->searchable()
                        ->preload()
                        ->visibleJs("! {$hasScopedInstitutionJs} && \$get('primary_organizer_kind') === 'person'")
                        ->required(fn (Get $get): bool => $this->selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' && ! filled($get('primary_organizer_id')))
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('primary_organizer_id', is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null);
                        })
                        ->afterStateUpdatedJs(<<<'JS'
                                                    if ($state) {
                                                        const currentPersons = $get('persons') || []
                                                        if (!currentPersons.includes($state)) {
                                                            $set('persons', [...currentPersons, $state])
                                                        }
                                                    }
                                                    JS)
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
                        ->visibleJs($hasScopedInstitutionJs." || \$get('primary_organizer_kind') === 'institution'")
                        ->afterStateUpdatedJs("if (! {$hasScopedInstitutionJs}) {
                                        return
                                    }

                                    if (\$state) {
                                        \$set('location_type', 'institution')
                                        \$set('location_institution_id', \$get('primary_organizer_institution_id'))
                                        \$set('location_venue_id', null)
                                        return
                                    }

                                    \$set('location_type', 'venue')
                                    \$set('location_institution_id', null)
                                    \$set('space_id', null)"),

                    Radio::make('location_type')
                        ->label(__('Jenis Lokasi'))
                        ->options([
                            'institution' => __('Institusi'),
                            'venue' => __('Tempat'),
                        ])
                        ->inline()
                        ->default('institution')
                        ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution'))")
                        ->required(fn (Get $get): bool => ($this->selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && $get('event_format') !== 'online'),

                    Select::make('location_institution_id')
                        ->label(__('Institusi'))
                        ->options(fn (): array => $this->availableInstitutionOptions())
                        ->searchable()
                        ->preload()
                        ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution'")
                        ->required(fn (Get $get): bool => ($this->selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && $get('location_type') === 'institution')
                        ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                    Select::make('location_venue_id')
                        ->label(__('Lokasi'))
                        ->options(fn (): array => $this->cachedSubmitVenueOptions())
                        ->searchable()
                        ->preload()
                        ->visibleJs("({$hasScopedInstitutionJs} && !\$get('location_same_as_institution')) || (! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'venue')")
                        ->required(fn (Get $get): bool => ($this->selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) === 'person' || ! $get('location_same_as_institution')) && $get('location_type') === 'venue')
                        ->createOptionForm(VenueFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => VenueFormSchema::createOptionUsing($data, $schema)),

                    Select::make('space_ids')
                        ->label(__('Ruang'))
                        ->helperText(__('Pilih satu atau lebih ruang (cth: Dewan Utama, Ruang Solat).'))
                        ->placeholder(__('Pilih ruang…'))
                        ->searchable()
                        ->preload()
                        ->multiple()
                        ->visibleJs("({$hasScopedInstitutionJs} && (\$get('location_same_as_institution') !== false)) || (\$get('primary_organizer_kind') === 'institution' && (\$get('location_same_as_institution') !== false)) || ((\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution')")
                        ->options(
                            fn (): array => Space::query()
                                ->where('status', 'active')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray()
                        ),
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
                        ->label(__('Pilih Penceramah'))
                        ->required(fn (Get $get): bool => $this->categoriesRequirePersons($get('event_category_ids')))
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->availablePersonOptions())
                        ->helperText(fn (Get $get): string => $this->categoriesRequirePersons($get('event_category_ids'))
                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.'))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Person::query()->find($value)?->formatted_name)
                        ->getOptionLabelsUsing(fn (array $values): array => Person::query()
                            ->whereIn('id', $values)
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
                                ->label(__('Pautkan Profil Penceramah'))
                                ->options(fn (): array => $this->availablePersonOptions())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $set('display_name', null);
                                    $set('involveable_type', filled($state) ? 'person' : null);
                                })
                                ->getOptionLabelUsing(fn (mixed $value): ?string => Person::query()->find($value)?->formatted_name)
                                ->createOptionForm(PersonFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, Schema $schema): string => PersonFormSchema::createOptionUsing($data, $schema)),
                            Hidden::make('involveable_type'),
                            TextInput::make('display_name')
                                ->label(__('Nama Paparan'))
                                ->maxLength(255)
                                ->required(fn (Get $get): bool => blank($get('involveable_id')))
                                ->disabled(fn (Get $get): bool => filled($get('involveable_id')))
                                ->dehydrated(fn (Get $get): bool => blank($get('involveable_id')))
                                ->helperText(__('Isi nama jika tiada profil penceramah dipautkan.')),
                            Select::make('visibility')
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
                        SchemaView::make('components.pages.submit-event.partials.review-preview'),
                    ]),

                Section::make(__('Maklumat Anda'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextInput::make('submitter_name')
                                    ->label(__('Nama Anda'))
                                    ->required()
                                    ->maxLength(100),

                                TextInput::make('submitter_email')
                                    ->label(__('Email'))
                                    ->email()
                                    ->maxLength(255)
                                    ->required(fn (Get $get) => ! auth()->check() && empty($get('submitter_phone'))),

                                TextInput::make('submitter_phone')
                                    ->label(__('Telefon'))
                                    ->tel()
                                    ->maxLength(20)
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

    public function submit(): mixed
    {
        $state = $this->eventForm()->getState();
        $state['captcha_token'] = $this->data['captcha_token'] ?? null;

        $eventContainer = $this->selectedEventContainer();
        $result = app(SubmitFrontendEventAction::class)->handle(
            state: $state,
            request: request(),
            submitter: $this->submitterUser(),
            eventContainer: $eventContainer,
            scopedInstitution: $this->scopedInstitution(),
            persistRelationships: function (Event $event): void {
                $this->eventForm()->model($event);
                $this->eventForm()->saveRelationships();
            },
            validationKeyPrefix: 'data.',
        );

        $event = $result['event'];

        session()->flash('event_title', $event->title);
        session()->flash('event_slug', $event->slug);
        session()->flash('event_auto_approved', $result['auto_approved']);
        session()->flash('submission_institution_id', $this->scopedInstitutionId);
        session()->flash('event_visibility', $result['visibility']);

        if ($eventContainer instanceof Event) {
            session()->flash('event_container_id', $eventContainer->id);
            session()->flash('event_container_title', $eventContainer->title);
        }

        return redirect()->route('submit-event.success');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function normalizeScopedInstitutionState(array $validated): array
    {
        $institution = $this->scopedInstitution();

        if (! $institution instanceof Institution) {
            return $validated;
        }

        $validated['primary_organizer_kind'] = 'institution';
        $validated['primary_organizer_id'] = $institution->id;
        $validated['primary_organizer_institution_id'] = $institution->id;
        $validated['primary_organizer_person_id'] = null;
        $validated['location_same_as_institution'] = (bool) ($validated['location_same_as_institution'] ?? true);

        if (($validated['event_format'] ?? EventFormat::Physical->value) === EventFormat::Online->value) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $institution->id;
            $validated['location_venue_id'] = null;

            return $validated;
        }

        if ($validated['location_same_as_institution']) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $institution->id;
            $validated['location_venue_id'] = null;

            return $validated;
        }

        $validated['location_type'] = 'venue';
        $validated['location_institution_id'] = null;

        if (! filled($validated['location_venue_id'] ?? null)) {
            throw ValidationException::withMessages([
                'data.location_venue_id' => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }

        return $validated;
    }

    protected function shouldAutoApproveSubmission(): bool
    {
        return $this->hasScopedInstitution();
    }

    protected function selectedEventContainer(): ?Event
    {
        $eventId = $this->eventId;

        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            return null;
        }

        $event = Event::query()
            ->with(['institution:id,name', 'accessPolicy'])
            ->find($eventId);

        $scopedInstitution = $this->scopedInstitution();

        if (
            $event instanceof Event
            && $scopedInstitution instanceof Institution
            && ! $this->eventMatchesScopedInstitution($event, $scopedInstitution)
        ) {
            abort(403);
        }

        return $event instanceof Event ? $event : null;
    }

    protected function eventMatchesScopedInstitution(Event $event, Institution $institution): bool
    {
        if ($event->institution_id === $institution->id) {
            return true;
        }

        $involvement = $event->primaryOrganizerInvolvement;

        return $involvement !== null
            && $involvement->involveable_type === Institution::class
            && $involvement->involveable_id === $institution->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventContainerDefaults(Event $event): array
    {
        $eventVisibility = $event->visibility;

        $defaults = [
            'visibility' => $eventVisibility instanceof EventVisibility
                ? $eventVisibility->value
                : (is_string($eventVisibility) && $eventVisibility !== '' ? $eventVisibility : EventVisibility::Public->value),
        ];

        $organizer = $event->primaryOrganizerInvolvement;

        if ($organizer?->involveable_type === Institution::class && filled($organizer->involveable_id)) {
            $defaults['primary_organizer_kind'] = 'institution';
            $defaults['primary_organizer_id'] = $organizer->involveable_id;
            $defaults['primary_organizer_institution_id'] = $organizer->involveable_id;
            $defaults['primary_organizer_person_id'] = null;
            $defaults['location_same_as_institution'] = true;
            $defaults['location_type'] = 'institution';
            $defaults['location_institution_id'] = $event->institution_id ?: $organizer->involveable_id;
        }

        if ($organizer?->involveable_type === Person::class && filled($organizer->involveable_id)) {
            $defaults['primary_organizer_kind'] = 'person';
            $defaults['primary_organizer_id'] = $organizer->involveable_id;
            $defaults['primary_organizer_institution_id'] = null;
            $defaults['primary_organizer_person_id'] = $organizer->involveable_id;
            $defaults['location_type'] = $event->default_venue_id ? 'venue' : 'institution';
            $defaults['location_institution_id'] = $event->institution_id;

            if ($event->default_venue_id) {
                $defaults['location_same_as_institution'] = false;
                $defaults['location_venue_id'] = $event->default_venue_id;
            }
        }

        return $defaults;
    }

    protected function selectedDuplicateEvent(): ?Event
    {
        $duplicateId = $this->duplicateEventId;

        if (! is_string($duplicateId) || ! Str::isUuid($duplicateId)) {
            return null;
        }

        $duplicateEvent = Event::query()
            ->with([
                'tags:id,type,status',
                'references:id,title',
                'languages:id,code',
                'persons',
                'keyPeople.person',
            ])
            ->find($duplicateId);

        abort_unless($duplicateEvent instanceof Event && $this->canDuplicateSourceEvent($duplicateEvent), 404);

        return $duplicateEvent;
    }

    protected function canDuplicateSourceEvent(Event $event): bool
    {
        $user = $this->submitterUser();

        if ($user instanceof User && ($user->can('update', $event) || $this->isDuplicateEventOwner($event))) {
            return true;
        }

        $eventVisibility = $event->visibility instanceof EventVisibility
            ? $event->visibility->value
            : (string) $event->visibility;

        return $event->published_at !== null
            && $this->isPubliclyVisibleDuplicateStatus($event)
            && in_array($eventVisibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true);
    }

    protected function isDuplicateEventOwner(Event $event): bool
    {
        $user = $this->submitterUser();

        if (! $user instanceof User) {
            return false;
        }

        return EventSubmission::query()
            ->where('event_id', $event->id)
            ->where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', $user->id)
            ->exists();
    }

    protected function isPubliclyVisibleDuplicateStatus(Event $event): bool
    {
        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Approved::class)
                || $status->equals(Pending::class)
                || $status->equals(Cancelled::class);
        }

        return in_array((string) $status, Event::PUBLIC_STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function duplicateEventDefaults(Event $duplicateEvent): array
    {
        $timezone = $this->resolveSubmissionTimezone($this->data['submission_country_id'] ?? null);
        $eventFormat = $duplicateEvent->delivery_mode instanceof EventFormat
            ? $duplicateEvent->delivery_mode->value
            : (is_string($duplicateEvent->delivery_mode) ? $duplicateEvent->delivery_mode : EventFormat::Physical->value);
        $visibility = $duplicateEvent->visibility instanceof EventVisibility
            ? $duplicateEvent->visibility->value
            : (is_string($duplicateEvent->visibility) ? $duplicateEvent->visibility : EventVisibility::Public->value);
        $gender = $duplicateEvent->gender instanceof EventGenderRestriction
            ? $duplicateEvent->gender->value
            : (is_string($duplicateEvent->gender) ? $duplicateEvent->gender : EventGenderRestriction::All->value);
        $defaults = [
            'title' => $duplicateEvent->title,
            'description' => $this->duplicateEventDescription($duplicateEvent),
            'event_category_ids' => $duplicateEvent->classifications
                ->where('taxonomy_code', 'event_category')
                ->pluck('event_term_id')
                ->filter()
                ->values()
                ->all(),
            'event_format' => $eventFormat,
            'visibility' => $visibility,
            'gender' => $gender,
            'age_group' => $this->normalizeAgeGroupState($duplicateEvent->age_group),
            'children_allowed' => (bool) $duplicateEvent->children_allowed,
            'is_muslim_only' => (bool) $duplicateEvent->is_muslim_only,
            'event_url' => $duplicateEvent->event_url,
            'live_url' => $duplicateEvent->live_url,
            'domain_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Domain->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'discipline_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Discipline->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'source_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Source->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'issue_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Issue->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'references' => $duplicateEvent->references->pluck('id')->values()->all(),
            'persons' => $this->duplicatePersonState($duplicateEvent),
            'other_key_people' => $this->duplicateOtherKeyPeopleState($duplicateEvent),
        ];

        if ($duplicateEvent->languages->isNotEmpty()) {
            $defaults['languages'] = $duplicateEvent->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        }

        if ($duplicateEvent->starts_at instanceof CarbonInterface) {
            $startsAt = $duplicateEvent->starts_at->copy()->timezone($timezone);
            $prayerTime = $this->duplicateEventPrayerTime($duplicateEvent);

            $defaults['event_date'] = $startsAt->toDateString();
            $defaults['prayer_time'] = $prayerTime->value;

            if ($prayerTime->isCustomTime()) {
                $defaults['custom_time'] = $startsAt->format('H:i');
            }
        }

        if ($duplicateEvent->ends_at instanceof CarbonInterface) {
            $defaults['end_time'] = $duplicateEvent->ends_at->copy()->timezone($timezone)->format('H:i');
        }

        return array_replace($defaults, $this->duplicateOrganizerAndLocationDefaults($duplicateEvent));
    }

    protected function duplicateEventDescription(Event $duplicateEvent): string
    {
        $description = $duplicateEvent->description;

        if (is_string($description)) {
            return $description;
        }

        $html = data_get($description, 'html');

        if (is_string($html) && $html !== '') {
            return $html;
        }

        $content = data_get($description, 'content');

        if (is_string($content) && $content !== '') {
            return $content;
        }

        return $duplicateEvent->description_text;
    }

    /**
     * @return list<string>
     */
    protected function normalizeEventCategoryState(mixed $state): array
    {
        if ($state instanceof Collection) {
            return $state
                ->map(strval(...))
                ->filter(fn (string $termId): bool => $termId !== '')
                ->values()
                ->all();
        }

        if (is_array($state)) {
            return collect($state)
                ->map(strval(...))
                ->filter(fn (string $termId): bool => $termId !== '')
                ->values()
                ->all();
        }

        if (is_string($state) && $state !== '') {
            return [$state];
        }

        return [];
    }

    protected function duplicateEventPrayerTime(Event $duplicateEvent): EventPrayerTime
    {
        $prayerDisplayText = $duplicateEvent->prayer_display_text;

        if (is_string($prayerDisplayText) && $prayerDisplayText !== '') {
            foreach (EventPrayerTime::cases() as $prayerTime) {
                if ($prayerTime->getLabel() === $prayerDisplayText) {
                    return $prayerTime;
                }
            }
        }

        $prayerReference = $duplicateEvent->prayer_reference instanceof BackedEnum
            ? (string) $duplicateEvent->prayer_reference->value
            : (is_string($duplicateEvent->prayer_reference) ? $duplicateEvent->prayer_reference : null);
        $prayerOffset = $duplicateEvent->prayer_offset instanceof BackedEnum
            ? (string) $duplicateEvent->prayer_offset->value
            : (is_string($duplicateEvent->prayer_offset) ? $duplicateEvent->prayer_offset : null);

        if (is_string($prayerReference) && $prayerReference !== '') {
            foreach (EventPrayerTime::cases() as $prayerTime) {
                if ($prayerTime->isCustomTime()) {
                    continue;
                }

                if (
                    $prayerTime->toPrayerReference()?->value === $prayerReference
                    && $prayerTime->getDefaultOffset()?->value === $prayerOffset
                ) {
                    return $prayerTime;
                }
            }
        }

        return EventPrayerTime::LainWaktu;
    }

    /**
     * @return list<string>
     */
    protected function duplicatePersonState(Event $duplicateEvent): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        return $duplicateEvent->persons
            ->pluck('id')
            ->map(fn (mixed $personId): ?string => is_string($personId) && $access->canUsePerson($submitter, $personId) ? $personId : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{role_code: string, involveable_type: ?string, involveable_id: ?string, display_name: ?string, visibility: string, notes: ?string}>
     */
    protected function duplicateOtherKeyPeopleState(Event $duplicateEvent): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        return $duplicateEvent->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $keyPerson->role_code !== EventKeyPersonRole::Speaker->value)
            ->map(function (EventKeyPerson $keyPerson) use ($access, $submitter): array {
                $personId = is_string($keyPerson->involveable_id) && $access->canUsePerson($submitter, $keyPerson->involveable_id)
                    ? $keyPerson->involveable_id
                    : null;

                $fallbackName = $personId === null
                    ? $keyPerson->display_name
                    : null;

                return [
                    'role_code' => (string) $keyPerson->role_code,
                    'involveable_type' => $personId === null ? null : 'person',
                    'involveable_id' => $personId,
                    'display_name' => filled($fallbackName) ? (string) $fallbackName : null,
                    'visibility' => $keyPerson->visibility ?? 'public',
                    'notes' => filled($keyPerson->notes) ? (string) $keyPerson->notes : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function duplicateOrganizerAndLocationDefaults(Event $duplicateEvent): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();
        $defaults = [];
        $eventFormat = $duplicateEvent->delivery_mode instanceof EventFormat
            ? $duplicateEvent->delivery_mode->value
            : (is_string($duplicateEvent->delivery_mode) ? $duplicateEvent->delivery_mode : EventFormat::Physical->value);
        $organizer = $duplicateEvent->primaryOrganizerInvolvement;
        $organizerId = $organizer !== null ? $organizer->involveable_id : null;
        $institutionId = is_string($duplicateEvent->institution_id) ? $duplicateEvent->institution_id : null;

        if ($organizer?->involveable_type === Institution::class && $organizerId !== null && $access->canUseInstitution($submitter, $organizerId)) {
            $defaults['primary_organizer_kind'] = 'institution';
            $defaults['primary_organizer_id'] = $organizerId;
            $defaults['primary_organizer_institution_id'] = $organizerId;
            $defaults['primary_organizer_person_id'] = null;
        }

        if ($organizer?->involveable_type === Person::class && $organizerId !== null && $access->canUsePerson($submitter, $organizerId)) {
            $defaults['primary_organizer_kind'] = 'person';
            $defaults['primary_organizer_id'] = $organizerId;
            $defaults['primary_organizer_institution_id'] = null;
            $defaults['primary_organizer_person_id'] = $organizerId;
        }

        if ($eventFormat === EventFormat::Online->value) {
            return $defaults;
        }

        if (filled($duplicateEvent->default_venue_id)) {
            $defaults['location_same_as_institution'] = false;
            $defaults['location_type'] = 'venue';
            $defaults['location_venue_id'] = $duplicateEvent->default_venue_id;
            $defaults['location_institution_id'] = null;

            return $defaults;
        }

        if ($institutionId !== null && $access->canUseInstitution($submitter, $institutionId)) {
            $defaults['location_type'] = 'institution';
            $defaults['location_institution_id'] = $institutionId;
            $defaults['location_same_as_institution'] = ($defaults['primary_organizer_kind'] ?? null) === 'institution'
                && ($defaults['primary_organizer_id'] ?? null) === $institutionId;
        }

        return $defaults;
    }

    public function eventManagementUrl(): ?string
    {
        $event = $this->selectedEventContainer();

        return $event instanceof Event
            ? EventResource::getUrl('view', ['record' => $event], panel: 'ahli')
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeAgeGroupState(mixed $state): array
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

    protected function submitterUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, string>
     */
    protected function availableInstitutionOptions(): array
    {
        if (($institution = $this->scopedInstitution()) instanceof Institution) {
            return [$institution->id => $institution->display_name];
        }

        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        if (! $submitter instanceof User) {
            return Cache::remember('submit_institutions', 60, fn (): array => $access->institutionQueryForSubmitter(null)
                ->orderBy('name')
                ->with('names')->get(['institutions.id', 'institutions.name'])
                ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
                ->all());
        }

        return $access->institutionQueryForSubmitter($submitter)
            ->orderBy('name')
            ->with('names')->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function availablePersonOptions(): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        if (! $submitter instanceof User) {
            return Cache::remember('submit_persons', 60, fn (): array => $access->personQueryForSubmitter(null)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
                ->all());
        }

        return $access->personQueryForSubmitter($submitter)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
            ->all();
    }

    protected function selectedPrimaryOrganizerKind(mixed $organizerKind, mixed $primaryOrganizerId): ?string
    {
        if (in_array($organizerKind, ['institution', 'person'], true)) {
            return $organizerKind;
        }

        return $this->resolvedPrimaryOrganizerType($primaryOrganizerId);
    }

    protected function resolvedPrimaryOrganizerInstitutionId(mixed $primaryOrganizerId): ?string
    {
        return $this->resolvedPrimaryOrganizerType($primaryOrganizerId) === 'institution'
            ? (is_scalar($primaryOrganizerId) && trim((string) $primaryOrganizerId) !== '' ? trim((string) $primaryOrganizerId) : null)
            : null;
    }

    protected function resolvedPrimaryOrganizerType(mixed $primaryOrganizerId): ?string
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

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function assertSubmissionEntitiesAreAccessible(array $validated): void
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        $organizerType = $this->selectedPrimaryOrganizerKind(
            $validated['primary_organizer_kind'] ?? ($this->data['primary_organizer_kind'] ?? null),
            $validated['primary_organizer_id'] ?? ($this->data['primary_organizer_id'] ?? null),
        );
        $primaryOrganizerId = (string) ($validated['primary_organizer_id'] ?? ($this->data['primary_organizer_id'] ?? ''));
        $locationInstitutionId = (string) ($validated['location_institution_id'] ?? ($this->data['location_institution_id'] ?? ''));

        if ($organizerType === 'institution' && $primaryOrganizerId !== '' && ! $access->canUseInstitution($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                'data.primary_organizer_id' => __('Anda tidak dibenarkan memilih institusi ini untuk penghantaran majlis.'),
            ]);
        }

        if ($organizerType === 'person' && $primaryOrganizerId !== '' && ! $access->canUsePerson($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                'data.primary_organizer_id' => __('Anda tidak dibenarkan memilih penceramah ini untuk penghantaran majlis.'),
            ]);
        }

        $eventFormat = $validated['event_format'] ?? EventFormat::Physical->value;
        $requiresLocationChoice = $organizerType === 'person' || ! ($validated['location_same_as_institution'] ?? true);
        $usesLocationInstitution = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && (($validated['location_type'] ?? 'institution') === 'institution');

        if ($usesLocationInstitution && $locationInstitutionId !== '' && ! $access->canUseInstitution($submitter, $locationInstitutionId)) {
            throw ValidationException::withMessages([
                'data.location_institution_id' => __('Anda tidak dibenarkan memilih institusi lokasi ini.'),
            ]);
        }

        $personIds = collect(array_merge(
            (array) ($validated['persons'] ?? []),
            collect((array) ($validated['other_key_people'] ?? []))
                ->pluck('involveable_id')
                ->all(),
            (array) ($this->data['persons'] ?? []),
        ))
            ->map(fn (mixed $value): ?string => filled($value) ? (string) $value : null)
            ->filter()
            ->unique()
            ->values();

        foreach ($personIds as $personId) {
            if (! $access->canUsePerson($submitter, $personId)) {
                throw ValidationException::withMessages([
                    'data.persons' => __('Senarai penceramah mengandungi pilihan yang tidak dibenarkan untuk penghantaran ini.'),
                ]);
            }
        }
    }

    protected function hasCommunityCategorySelection(mixed $categoryIds): bool
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

    protected function categoriesRequirePersons(mixed $categoryIds): bool
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

    /**
     * Resolve the starts_at datetime from event_date and prayer_time/custom_time.
     *
     * @param  array{event_date: string, prayer_time: string|EventPrayerTime, custom_time?: string|null, submission_country_id?: string|null}  $validated
     */
    protected function resolveStartsAt(array $validated): Carbon
    {
        $timezone = $this->resolveSubmissionTimezone($validated['submission_country_id'] ?? null);
        $eventDate = Carbon::parse($validated['event_date'], $timezone)->startOfDay();
        $prayerTimeValue = $validated['prayer_time'] ?? '';
        $prayerTime = $prayerTimeValue instanceof EventPrayerTime
            ? $prayerTimeValue
            : EventPrayerTime::tryFrom($prayerTimeValue);

        if ($prayerTime?->isCustomTime() && ! empty($validated['custom_time'])) {
            $time = Carbon::parse($validated['custom_time']);

            return $eventDate->setTime($time->hour, $time->minute)->utc();
        }

        $defaultTimes = $this->getDefaultPrayerTimes();

        $timeString = $defaultTimes[$prayerTime instanceof EventPrayerTime ? $prayerTime->value : ''] ?? '20:00';
        $time = Carbon::parse($timeString);

        return $eventDate->setTime($time->hour, $time->minute)->utc();
    }

    /**
     * Resolve the ends_at datetime from end_time using the same date as starts_at.
     *
     * @param  array{end_time?: string|null}  $validated
     */
    protected function resolveEndsAt(array $validated, Carbon $startsAt, string $timezone): ?Carbon
    {
        $endTimeValue = $validated['end_time'] ?? null;

        if (empty($endTimeValue)) {
            return null;
        }

        $time = Carbon::parse($endTimeValue);
        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);

        return $startInUserTimezone->setTime($time->hour, $time->minute)->utc();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function validateEndsAtAfterStartsAt(array $validated, Carbon $startsAt, string $timezone): void
    {
        $endTimeValue = $validated['end_time'] ?? null;

        if (! is_string($endTimeValue) || $endTimeValue === '') {
            return;
        }

        $endTime = Carbon::parse($endTimeValue);
        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);
        $endInUserTimezone = $startInUserTimezone->copy()->setTime($endTime->hour, $endTime->minute);

        if ($endInUserTimezone->lessThanOrEqualTo($startInUserTimezone)) {
            throw ValidationException::withMessages([
                'data.end_time' => __('Masa akhir mestilah selepas masa mula.'),
            ]);
        }
    }

    protected function resolveStartTimeForComparison(mixed $prayerTimeValue, mixed $customTime): ?string
    {
        $prayerTime = $prayerTimeValue instanceof EventPrayerTime
            ? $prayerTimeValue
            : EventPrayerTime::tryFrom((string) $prayerTimeValue);

        if ($prayerTime?->isCustomTime()) {
            return is_string($customTime) && $customTime !== '' ? $customTime : null;
        }

        if (! $prayerTime instanceof EventPrayerTime) {
            return null;
        }

        return $this->getDefaultPrayerTimes()[$prayerTime->value] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultPrayerTimes(): array
    {
        return [
            EventPrayerTime::SelepasSubuh->value => '06:30',
            EventPrayerTime::SelepasZuhur->value => '13:30',
            EventPrayerTime::SebelumJumaat->value => '13:45',
            EventPrayerTime::SelepasJumaat->value => '14:00',
            EventPrayerTime::SelepasAsar->value => '17:00',
            EventPrayerTime::SebelumMaghrib->value => '19:45',
            EventPrayerTime::SelepasMaghrib->value => '20:00',
            EventPrayerTime::SelepasIsyak->value => '21:30',
            EventPrayerTime::SelepasTarawih->value => '22:30',
        ];
    }

    /**
     * Check if a given date falls within Ramadhan.
     * This uses approximate Gregorian dates for Ramadhan periods.
     */
    protected function isRamadhan(Carbon $date, ?string $timezone = null): bool
    {
        $timezone ??= $this->resolveSubmissionTimezone($this->data['submission_country_id'] ?? null);
        $year = $date->year;

        $ramadhanPeriods = [
            2026 => ['start' => '02-18', 'end' => '03-19'],
            2027 => ['start' => '02-07', 'end' => '03-08'],
            2028 => ['start' => '01-27', 'end' => '02-25'],
            2029 => ['start' => '01-16', 'end' => '02-13'],
            2030 => ['start' => '01-05', 'end' => '02-03'],
        ];

        if (! isset($ramadhanPeriods[$year])) {
            return false;
        }

        $period = $ramadhanPeriods[$year];
        $startDate = Carbon::parse("{$year}-{$period['start']}", $timezone)->startOfDay();
        $endDate = Carbon::parse("{$year}-{$period['end']}", $timezone)->endOfDay();

        return $date->between($startDate, $endDate);
    }

    protected function resolveSubmissionCountryId(mixed $countryId = null): ?string
    {
        $resolvedCountryId = app(AddressCountryResolver::class)->resolveId($countryId);

        if (is_string($resolvedCountryId)) {
            return $resolvedCountryId;
        }

        if ($countryId === null || (is_string($countryId) && trim($countryId) === '')) {
            return $this->defaultSubmissionCountryId();
        }

        return null;
    }

    protected function resolveSubmissionTimezone(mixed $countryId = null): string
    {
        return app(AddressCountryResolver::class)->timezoneFor($this->resolveSubmissionCountryId($countryId))
            ?? config('app.timezone', 'UTC');
    }

    protected function defaultSubmissionCountryId(): ?string
    {
        return app(AddressCountryResolver::class)->resolveId('MY');
    }

    /**
     * @param  array{submitter_email?: string|null, submitter_phone?: string|null}  $validated
     */
    protected function storeSubmitterContacts(EventSubmission $submission, array $validated): void
    {
        $email = $validated['submitter_email'] ?? null;
        $phone = $validated['submitter_phone'] ?? null;

        if (filled($email)) {
            $submission->contactMethods()->create([
                'type' => ContactMethodType::Email->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $email,
                'is_public' => false,
            ]);
        }

        if (filled($phone)) {
            $submission->contactMethods()->create([
                'type' => ContactMethodType::Phone->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $phone,
                'is_public' => false,
            ]);
        }
    }

    protected function assertCaptchaIsValid(?string $captchaToken): void
    {
        $verifier = app(TurnstileVerifier::class);

        if (! $verifier->verify($captchaToken, request()->ip())) {
            throw ValidationException::withMessages([
                'data.captcha_token' => __('Sila lengkapkan pengesahan keselamatan sebelum menghantar.'),
            ]);
        }
    }
}
