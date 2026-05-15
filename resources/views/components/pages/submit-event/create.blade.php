<?php

use App\Actions\Events\SubmitFrontendEventAction;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Enums\RegistrationMode;
use App\Enums\TagType;
use App\Filament\Ahli\Resources\Events\EventResource;
use App\Forms\Components\Select;
use App\Forms\InstitutionFormSchema;
use App\Forms\SharedFormSchema;
use App\Forms\SpeakerFormSchema;
use App\Forms\VenueFormSchema;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSettings;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ai\EventMediaExtractionService;
use App\Services\Captcha\TurnstileVerifier;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryRegistry;
use App\Support\Submission\EntitySubmissionAccess;
use Carbon\CarbonInterface;
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
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Nnjeim\World\Models\Language;

/** @phpstan-ignore-next-line Anonymous Livewire component entrypoint. */
new #[Layout('layouts.app')] class extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    private const REVIEW_STEP_ID = 'form.semak-sebelum-hantar::data::wizard-step';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Url(as: 'step')]
    public ?string $wizardStep = null;

    public ?string $parentEventId = null;

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
        $submissionCountryId = $this->resolveSubmissionCountryId();
        $this->parentEventId = request()->query('parent');
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
            'languages' => [101], // Malay as default
            'event_format' => EventFormat::Physical,
            'visibility' => EventVisibility::Public->value,
            'location_same_as_institution' => true,
            'location_type' => 'institution',
            'is_muslim_only' => false,
            'other_key_people' => [],
            'captcha_token' => null,
            'submission_country_id' => $submissionCountryId,
        ];

        if ($parentEvent = $this->selectedParentEvent()) {
            $state = array_replace($state, $this->parentEventDefaults($parentEvent));
        }

        if ($duplicateEvent = $this->selectedDuplicateEvent()) {
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
            'organizer_type' => 'institution',
            'organizer_institution_id' => $institution->id,
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

        return Cache::remember($this->submitCacheKey('submit_languages'), 3600, function () use ($preferredLabels, $preferredOrder): array {
            return Language::query()
                ->whereIn('code', $preferredOrder)
                ->get()
                ->sortBy(fn (Language $language): int|false => array_search((string) $language->code, $preferredOrder, true))
                ->mapWithKeys(function (Language $language) use ($preferredLabels): array {
                    $code = (string) $language->code;
                    $label = $preferredLabels[$code]
                        ?? (string) ($language->name ?? Str::upper($code));

                    return [$language->id => $label];
                })
                ->all();
        });
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    protected function cachedSubmitTagOptions(TagType $type, string $cachePrefix, array $statuses): array
    {
        return Cache::remember($this->submitCacheKey($cachePrefix.'_'.app()->getLocale()), 60, function () use ($statuses, $type): array {
            return Tag::query()
                ->where('type', $type)
                ->whereIn('status', $statuses)
                ->orderBy('order_column')
                ->get()
                ->mapWithKeys(fn (Tag $tag): array => [(string) $tag->id => $tag->getTranslation('name', app()->getLocale())])
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
            ->where('is_active', true)
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

            if ($sourceRatio === '4:5' && blank($mergedState['poster'] ?? null)) {
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

        if (! is_string($path) || $path === '') {
            return null;
        }

        $dimensions = @getimagesize($path);

        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
            return null;
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];

        return match (true) {
            $width > 0 && $height > 0 && abs(($width / $height) - (16 / 9)) < 0.01 => '16:9',
            $width > 0 && $height > 0 && abs(($width / $height) - (4 / 5)) < 0.01 => '4:5',
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

        $wizard = Wizard::make([
            Step::make(__('Maklumat Majlis'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    Select::make('event_type')
                        ->label(__('Jenis Majlis'))
                        ->required()
                        ->multiple()
                        ->closeOnSelect()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if ($this->hasCommunityEventTypeSelection($state)) {
                                $set('event_format', EventFormat::Physical->value);
                            }
                        })
                        ->options(function (): array {
                            return collect(EventType::cases())
                                ->mapToGroups(fn (EventType $type) => [
                                    $type->getGroup() => [$type->value => $type->getLabel()],
                                ])
                                ->map(fn ($group) => $group->collapse())
                                ->toArray();
                        })
                        ->searchable(),

                    Select::make('title')
                        ->label(__('Tajuk Majlis'))
                        ->required()
                        ->searchable()
                        ->allowHtml()
                        ->live()
                        ->getSearchResultsUsing(function (string $search): array {
                            if (empty($search)) {
                                return [];
                            }

                            $results = Event::query()
                                ->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower($search).'%'])
                                ->where('status', 'approved')
                                ->limit(10)
                                ->pluck('title', 'title')
                                ->toArray();

                            // Add Quick Add option if no exact match
                            $exactMatch = collect($results)->contains(fn ($value) => strtolower($value) === strtolower($search));

                            if (! $exactMatch) {
                                $results = ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".__('Tambah')." '{$search}'</span>"] + $results;
                            }

                            return $results;
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            // Clean up the quick_add prefix for display
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
                            // Skip quick-add values — they're new titles, not existing events
                            if (! $state || str_starts_with($state, '__quick_add__')) {
                                return;
                            }

                            // Find the latest approved event with this exact title
                            $existingEvent = Event::query()
                                ->where('title', $state)
                                ->where('status', 'approved')
                                ->with(['tags', 'references'])
                                ->latest()
                                ->first();

                            if (! $existingEvent) {
                                return;
                            }

                            // Populate event type
                            if ($existingEvent->event_type) {
                                $set('event_type', $existingEvent->event_type->map(fn ($e) => $e->value)->toArray());
                            }

                            // Populate tags by type
                            $tagsByType = $existingEvent->tags->groupBy('type');

                            if ($tagsByType->has(TagType::Domain->value)) {
                                $set('domain_tags', $tagsByType->get(TagType::Domain->value)->pluck('id')->toArray());
                            }
                            if ($tagsByType->has(TagType::Discipline->value)) {
                                $set('discipline_tags', $tagsByType->get(TagType::Discipline->value)->pluck('id')->toArray());
                            }
                            if ($tagsByType->has(TagType::Source->value)) {
                                $set('source_tags', $tagsByType->get(TagType::Source->value)->pluck('id')->toArray());
                            }
                            if ($tagsByType->has(TagType::Issue->value)) {
                                $set('issue_tags', $tagsByType->get(TagType::Issue->value)->pluck('id')->toArray());
                            }

                            // Populate references
                            if ($existingEvent->references->isNotEmpty()) {
                                $set('references', $existingEvent->references->pluck('id')->toArray());
                            }
                        })
                        ->placeholder(__('Cari atau masukkan tajuk majlis...')),

                    RichEditor::make('description')
                        ->label(__('Keterangan'))
                        ->maxLength(5000)
                        ->disableToolbarButtons(['table'])
                        ->floatingToolbars([])
                        ->placeholder(__('Terangkan mengenai majlis, topik yang akan dikupas, dll.')),

                    Grid::make(['default' => 1, 'sm' => 2, 'md' => 6])
                        ->schema([
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
                                                // No date selected — show base options only (no Jumaat/Tarawih/Ramadhan-only options)
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
                                                return $this->isRamadhan($date);
                                            }

                                            if ($case === EventPrayerTime::SelepasTarawih) {
                                                return $this->isRamadhan($date);
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
                                ->rule(function (Get $get): Closure {
                                    return function (string $attribute, $value, Closure $fail) use ($get) {
                                        $eventDate = $get('event_date');
                                        $timezone = $this->resolveSubmissionTimezone($get('submission_country_id'));
                                        $now = Carbon::now($timezone);

                                        if (! $eventDate || ! $value) {
                                            return;
                                        }

                                        $eventDay = Carbon::parse($eventDate, $timezone)->startOfDay();

                                        // Only validate if event is today
                                        if ($eventDay->isSameDay($now)) {
                                            $timeParts = explode(':', $value);
                                            $selectedTime = $eventDay->copy()
                                                ->setHour((int) $timeParts[0])
                                                ->setMinute((int) $timeParts[1]);

                                            if ($selectedTime->lessThan($now)) {
                                                $fail(__('Masa yang dipilih tidak boleh pada masa lalu untuk majlis hari ini.'));
                                            }
                                        }
                                    };
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
                                ->rule(function (Get $get): Closure {
                                    return function (string $attribute, $value, Closure $fail) use ($get) {
                                        if (! $value) {
                                            return; // Optional field
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
                                    };
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
                                    fn (string $value, Get $get): bool => $this->hasCommunityEventTypeSelection($get('event_type'))
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
                ]),

            Step::make(__('Kategori & Bidang'))
                ->icon('heroicon-o-tag')
                ->schema([
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

                                    if (! empty($uuids)) {
                                        $labels = array_merge($labels, Tag::whereIn('id', $uuids)->pluck('name', 'id')->all());
                                    }

                                    return $labels;
                                })
                                ->options(fn (): array => $this->cachedSubmitTagOptions(
                                    type: TagType::Domain,
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
                                    type: TagType::Discipline,
                                    cachePrefix: 'submit_tags_discipline_verified',
                                    statuses: ['verified'],
                                ))
                                ->getSearchResultsUsing(function (string $search): array {
                                    if (blank($search)) {
                                        // No search, let options() handle preload
                                        return [];
                                    }

                                    $results = Tag::query()
                                        ->where('type', TagType::Discipline)
                                        ->where('status', 'verified')
                                        ->where('name', 'like', "%{$search}%")
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

                                    if (! empty($uuids)) {
                                        $labels = array_merge($labels, Tag::whereIn('id', $uuids)->pluck('name', 'id')->all());
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

                                    if (! empty($uuids)) {
                                        $labels = array_merge($labels, Tag::whereIn('id', $uuids)->pluck('name', 'id')->all());
                                    }

                                    return $labels;
                                })
                                ->options(fn (): array => $this->cachedSubmitTagOptions(
                                    type: TagType::Source,
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
                                    type: TagType::Issue,
                                    cachePrefix: 'submit_tags_issue_verified',
                                    statuses: ['verified'],
                                ))
                                ->getSearchResultsUsing(function (string $search): array {
                                    if (blank($search)) {
                                        // No search, let options() handle preload
                                        return [];
                                    }

                                    $results = Tag::query()
                                        ->where('type', TagType::Issue)
                                        ->where('status', 'verified')
                                        ->where('name', 'like', "%{$search}%")
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

                                    if (! empty($uuids)) {
                                        $labels = array_merge($labels, Tag::whereIn('id', $uuids)->pluck('name', 'id')->all());
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
                        ->relationship('references', 'title', fn (Builder $query) => $query->where('is_active', true))
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
                                'publication_year' => filled($data['publication_year'] ?? null) ? (string) $data['publication_year'] : null,
                                'publisher' => $data['publisher'] ?? null,
                                'description' => $data['description'] ?? null,
                                'is_canonical' => false,
                                'status' => 'pending',
                                'is_active' => true,
                            ]);

                            // Save media uploads via Filament's relationship-saving mechanism
                            $schema->model($reference)->saveRelationships();

                            // Save reference URL as social media link
                            if (! empty($data['reference_url'])) {
                                $reference->socialMedia()->create([
                                    'platform' => 'website',
                                    'url' => $data['reference_url'],
                                ]);
                            }

                            return (string) $reference->getKey();
                        }),
                ]),

            Step::make(__('Penganjur & Lokasi'))
                ->icon('heroicon-o-building-office')
                ->schema([
                    Section::make(__('Penganjur'))
                        ->schema([
                            Radio::make('organizer_type')
                                ->label(__('Jenis Penganjur'))
                                ->required()
                                ->options([
                                    'institution' => __('Institusi'),
                                    'speaker' => __('Penceramah'),
                                ])
                                ->default('institution')
                                ->inline()
                                ->visible(! $hasScopedInstitution),

                            Select::make('organizer_institution_id')
                                ->label(__('Institusi'))
                                ->options(fn (): array => $this->availableInstitutionOptions())
                                ->searchable()
                                ->preload()
                                ->disabled($hasScopedInstitution)
                                ->dehydrated()
                                ->visibleJs($hasScopedInstitutionJs." || \$get('organizer_type') === 'institution'")
                                ->required(fn (Get $get): bool => $get('organizer_type') === 'institution')
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                            Select::make('organizer_speaker_id')
                                ->label(__('Penceramah'))
                                ->options(fn (): array => $this->availableSpeakerOptions())
                                ->searchable()
                                ->preload()
                                ->visibleJs("! {$hasScopedInstitutionJs} && \$get('organizer_type') === 'speaker'")
                                ->required(fn (Get $get): bool => $get('organizer_type') === 'speaker')
                                ->afterStateUpdatedJs(<<<'JS'
                                                            if ($state) {
                                                                const currentSpeakers = $get('speakers') || []
                                                                if (!currentSpeakers.includes($state)) {
                                                                    $set('speakers', [...currentSpeakers, $state])
                                                                }
                                                            }
                                                            JS)
                                ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                ->createOptionUsing(function (array $data, Schema $schema, Set $set, Get $get): string {
                                    return SpeakerFormSchema::createOptionUsing($data, $schema);
                                }),
                        ]),

                    Section::make(__('Lokasi'))
                        ->visibleJs(<<<'JS'
                                                    $get('event_format') !== 'online' && $get('organizer_type')
                                                    JS)
                        ->schema([
                            Toggle::make('location_same_as_institution')
                                ->label(__('Sama seperti institusi penganjur'))
                                ->default(true)
                                ->inline(false)
                                ->visibleJs($hasScopedInstitutionJs." || \$get('organizer_type') === 'institution'")
                                ->afterStateUpdatedJs("if (! {$hasScopedInstitutionJs}) {
                                                return
                                            }

                                            if (\$state) {
                                                \$set('location_type', 'institution')
                                                \$set('location_institution_id', \$get('organizer_institution_id'))
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
                                ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('organizer_type') === 'speaker' || !\$get('location_same_as_institution'))")
                                ->required(fn (Get $get): bool => ($get('organizer_type') === 'speaker' || ! $get('location_same_as_institution')) && $get('event_format') !== 'online'),

                            Select::make('location_institution_id')
                                ->label(__('Institusi'))
                                ->options(fn (): array => $this->availableInstitutionOptions())
                                ->searchable()
                                ->preload()
                                ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('organizer_type') === 'speaker' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution'")
                                ->required(fn (Get $get): bool => ($get('organizer_type') === 'speaker' || ! $get('location_same_as_institution')) && $get('location_type') === 'institution')
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, Schema $schema): string => InstitutionFormSchema::createOptionUsing($data, $schema)),

                            Select::make('location_venue_id')
                                ->label(__('Lokasi'))
                                ->options(fn (): array => $this->cachedSubmitVenueOptions())
                                ->searchable()
                                ->preload()
                                ->visibleJs("({$hasScopedInstitutionJs} && !\$get('location_same_as_institution')) || (! {$hasScopedInstitutionJs} && (\$get('organizer_type') === 'speaker' || !\$get('location_same_as_institution')) && \$get('location_type') === 'venue')")
                                ->required(fn (Get $get): bool => ($get('organizer_type') === 'speaker' || ! $get('location_same_as_institution')) && $get('location_type') === 'venue')
                                ->createOptionForm(VenueFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, Schema $schema): string => VenueFormSchema::createOptionUsing($data, $schema)),

                            Select::make('space_id')
                                ->label(__('Ruang'))
                                ->helperText(__('Pilihan: Pilih ruang tertentu di dalam institusi (cth: Dewan Utama, Ruang Solat).'))
                                ->placeholder(__('Pilih ruang…'))
                                ->searchable()
                                ->preload()
                                ->visibleJs("({$hasScopedInstitutionJs} && (\$get('location_same_as_institution') !== false)) || (\$get('organizer_type') === 'institution' && (\$get('location_same_as_institution') !== false)) || ((\$get('organizer_type') === 'speaker' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution')")
                                ->options(
                                    fn (): array => Space::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray()
                                ),
                        ]),
                ]),

            Step::make(__('Penceramah & Media'))
                ->icon('heroicon-o-user-group')
                ->schema([
                    Section::make(__('Penceramah'))
                        ->schema([
                            Select::make('speakers')
                                ->label(__('Pilih Penceramah'))
                                ->required(fn (Get $get): bool => $this->eventTypesRequireSpeakers($get('event_type')))
                                ->multiple()
                                ->closeOnSelect()
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => $this->availableSpeakerOptions())
                                ->helperText(fn (Get $get): string => $this->eventTypesRequireSpeakers($get('event_type'))
                                    ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                                    : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.'))
                                ->getOptionLabelUsing(fn (mixed $value): ?string => Speaker::query()->find($value)?->formatted_name)
                                ->getOptionLabelsUsing(fn (array $values): array => Speaker::query()
                                    ->whereIn('id', $values)
                                    ->get()
                                    ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                                    ->toArray())
                                ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, Schema $schema): string => SpeakerFormSchema::createOptionUsing($data, $schema)),

                            Repeater::make('other_key_people')
                                ->label(__('Peranan Lain'))
                                ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                                ->schema([
                                    Select::make('role')
                                        ->label(__('Peranan'))
                                        ->required()
                                        ->options(EventKeyPersonRole::nonSpeakerOptions())
                                        ->native(false),
                                    Select::make('speaker_id')
                                        ->label(__('Pautkan Profil Penceramah'))
                                        ->options(fn (): array => $this->availableSpeakerOptions())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, mixed $state): mixed => filled($state) ? $set('name', null) : null)
                                        ->getOptionLabelUsing(fn (mixed $value): ?string => Speaker::query()->find($value)?->formatted_name)
                                        ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                        ->createOptionUsing(fn (array $data, Schema $schema): string => SpeakerFormSchema::createOptionUsing($data, $schema)),
                                    TextInput::make('name')
                                        ->label(__('Nama Paparan'))
                                        ->maxLength(255)
                                        ->required(fn (Get $get): bool => blank($get('speaker_id')))
                                        ->disabled(fn (Get $get): bool => filled($get('speaker_id')))
                                        ->dehydrated(fn (Get $get): bool => blank($get('speaker_id')))
                                        ->helperText(__('Isi nama jika tiada profil penceramah dipautkan.')),
                                    Toggle::make('is_public')
                                        ->label(__('Papar Secara Awam'))
                                        ->default(true),
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
                                ->rules(['dimensions:ratio=16/9'])
                                ->conversion('thumb')
                                ->responsiveImages()
                                ->helperText(__('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.')),
                            SpatieMediaLibraryFileUpload::make('poster')
                                ->label(__('Poster Hebahan'))
                                ->collection('poster')
                                ->image()
                                ->imageEditor()
                                ->imageAspectRatio('4:5')
                                ->automaticallyOpenImageEditorForAspectRatio()
                                ->imageEditorAspectRatioOptions(['4:5', null])
                                ->automaticallyCropImagesToAspectRatio()
                                ->rules(['dimensions:ratio=4/5'])
                                ->conversion('thumb')
                                ->responsiveImages()
                                ->helperText(__('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 4:5 dan boleh mengandungi maklumat penuh.')),
                            SpatieMediaLibraryFileUpload::make('gallery')
                                ->label(__('Galeri'))
                                ->collection('gallery')
                                ->multiple()
                                ->reorderable()
                                ->maxFiles(10)
                                ->image()
                                ->imageEditor()
                                ->conversion('thumb')
                                ->responsiveImages()
                                ->helperText(__('Gambar tambahan untuk galeri majlis.')),
                        ])
                        ->columns(['default' => 1, 'sm' => 2]),
                ]),

            Step::make(__('Semak & Hantar'))
                ->id(self::REVIEW_STEP_ID)
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Hidden::make('submission_country_id')
                        ->dehydrated()
                        ->default(fn (): int => $this->resolveSubmissionCountryId()),

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
                ]),
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

        return $schema
            ->model(new Event)
            ->schema([
                $wizard,
            ])
            ->statePath('data');
    }

    public function submit(): mixed
    {
        $parentEvent = $this->selectedParentEvent();
        $result = app(SubmitFrontendEventAction::class)->handle(
            state: $this->eventForm()->getState(),
            request: request(),
            submitter: $this->submitterUser(),
            parentEvent: $parentEvent,
            scopedInstitution: $this->scopedInstitution(),
            persistRelationships: function (Event $event): void {
                $this->eventForm()->model($event);
                $this->eventForm()->saveRelationships();
            },
            validationKeyPrefix: 'data.',
        );

        $event = $result['event'];
        $submission = $result['submission'];

        session()->flash('event_title', $event->title);
        session()->flash('event_slug', $event->slug);
        session()->flash('event_auto_approved', $result['auto_approved']);
        session()->flash('submission_institution_id', $this->scopedInstitutionId);
        session()->flash('event_visibility', $result['visibility']);

        if ($parentEvent instanceof Event) {
            session()->flash('parent_event_id', $parentEvent->id);
            session()->flash('parent_event_title', $parentEvent->title);
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

        $validated['organizer_type'] = 'institution';
        $validated['organizer_institution_id'] = $institution->id;
        $validated['location_same_as_institution'] = (bool) ($validated['location_same_as_institution'] ?? true);

        if (($validated['event_format'] ?? EventFormat::Physical->value) === EventFormat::Online->value) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $institution->id;
            $validated['location_venue_id'] = null;

            return $validated;
        }

        if ($validated['location_same_as_institution'] === true) {
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

    protected function persistRegistrationSettings(Event $event): void
    {
        $parentEvent = $this->selectedParentEvent();

        if ($parentEvent instanceof Event && $parentEvent->settings !== null) {
            $registrationMode = $parentEvent->settings->registration_mode;
            $resolvedRegistrationMode = $registrationMode instanceof RegistrationMode
                ? $registrationMode->value
                : (is_string($registrationMode) && $registrationMode !== '' ? $registrationMode : RegistrationMode::Event->value);

            EventSettings::query()->updateOrCreate(
                ['event_id' => $event->id],
                [
                    'registration_required' => (bool) $parentEvent->settings->registration_required,
                    'registration_mode' => $resolvedRegistrationMode,
                ]
            );

            return;
        }

        EventSettings::query()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'registration_required' => false,
                'registration_mode' => RegistrationMode::Event->value,
            ]
        );
    }

    protected function shouldAutoApproveSubmission(): bool
    {
        return $this->hasScopedInstitution();
    }

    protected function selectedParentEvent(): ?Event
    {
        $parentId = $this->parentEventId;

        if (! is_string($parentId) || ! Str::isUuid($parentId)) {
            return null;
        }

        $parentEvent = Event::query()
            ->with(['institution:id,name', 'settings'])
            ->find($parentId);

        $scopedInstitution = $this->scopedInstitution();

        if (
            $parentEvent instanceof Event
            && $scopedInstitution instanceof Institution
            && ! $this->parentEventMatchesScopedInstitution($parentEvent, $scopedInstitution)
        ) {
            abort(403);
        }

        return $parentEvent instanceof Event && $parentEvent->isParentProgram()
            ? $parentEvent
            : null;
    }

    protected function parentEventMatchesScopedInstitution(Event $parentEvent, Institution $institution): bool
    {
        if ($parentEvent->institution_id === $institution->id) {
            return true;
        }

        return $parentEvent->organizer_type === Institution::class
            && $parentEvent->organizer_id === $institution->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parentEventDefaults(Event $parentEvent): array
    {
        $parentVisibility = $parentEvent->visibility;

        $defaults = [
            'visibility' => $parentVisibility instanceof EventVisibility
                ? $parentVisibility->value
                : (is_string($parentVisibility) && $parentVisibility !== '' ? $parentVisibility : EventVisibility::Public->value),
        ];

        if ($parentEvent->organizer_type === Institution::class && filled($parentEvent->organizer_id)) {
            $defaults['organizer_type'] = 'institution';
            $defaults['organizer_institution_id'] = $parentEvent->organizer_id;
            $defaults['location_same_as_institution'] = true;
            $defaults['location_type'] = 'institution';
            $defaults['location_institution_id'] = $parentEvent->institution_id ?: $parentEvent->organizer_id;
        }

        if ($parentEvent->organizer_type === Speaker::class && filled($parentEvent->organizer_id)) {
            $defaults['organizer_type'] = 'speaker';
            $defaults['organizer_speaker_id'] = $parentEvent->organizer_id;
            $defaults['location_type'] = $parentEvent->venue_id ? 'venue' : 'institution';
            $defaults['location_institution_id'] = $parentEvent->institution_id;

            if ($parentEvent->venue_id) {
                $defaults['location_same_as_institution'] = false;
                $defaults['location_venue_id'] = $parentEvent->venue_id;
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
                'languages:id',
                'speakers',
                'keyPeople.speaker',
            ])
            ->find($duplicateId);

        abort_unless($duplicateEvent instanceof Event && $this->canDuplicateSourceEvent($duplicateEvent), 404);

        return $duplicateEvent;
    }

    protected function canDuplicateSourceEvent(Event $event): bool
    {
        $user = $this->submitterUser();

        if ($user instanceof User) {
            if ($user->can('update', $event) || $this->isDuplicateEventOwner($event)) {
                return true;
            }
        }

        $eventVisibility = $event->visibility instanceof EventVisibility
            ? $event->visibility->value
            : (string) $event->visibility;

        return $event->is_active
            && $this->isPubliclyVisibleDuplicateStatus($event)
            && in_array($eventVisibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true);
    }

    protected function isDuplicateEventOwner(Event $event): bool
    {
        $user = $this->submitterUser();

        if (! $user instanceof User) {
            return false;
        }

        if ($event->user_id === $user->id || $event->submitter_id === $user->id) {
            return true;
        }

        return EventSubmission::query()
            ->where('event_id', $event->id)
            ->where('submitted_by', $user->id)
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
        $eventFormat = $duplicateEvent->event_format instanceof EventFormat
            ? $duplicateEvent->event_format->value
            : (is_string($duplicateEvent->event_format) ? $duplicateEvent->event_format : EventFormat::Physical->value);
        $visibility = $duplicateEvent->visibility instanceof EventVisibility
            ? $duplicateEvent->visibility->value
            : (is_string($duplicateEvent->visibility) ? $duplicateEvent->visibility : EventVisibility::Public->value);
        $gender = $duplicateEvent->gender instanceof EventGenderRestriction
            ? $duplicateEvent->gender->value
            : (is_string($duplicateEvent->gender) ? $duplicateEvent->gender : EventGenderRestriction::All->value);
        $defaults = [
            'title' => $duplicateEvent->title,
            'description' => $this->duplicateEventDescription($duplicateEvent),
            'event_type' => $this->normalizeEventTypeState($duplicateEvent->event_type),
            'event_format' => $eventFormat,
            'visibility' => $visibility,
            'gender' => $gender,
            'age_group' => $this->normalizeAgeGroupState($duplicateEvent->age_group),
            'children_allowed' => (bool) $duplicateEvent->children_allowed,
            'is_muslim_only' => (bool) $duplicateEvent->is_muslim_only,
            'event_url' => $duplicateEvent->event_url,
            'live_url' => $duplicateEvent->live_url,
            'domain_tags' => $duplicateEvent->tags->where('type', TagType::Domain->value)->pluck('id')->values()->all(),
            'discipline_tags' => $duplicateEvent->tags->where('type', TagType::Discipline->value)->pluck('id')->values()->all(),
            'source_tags' => $duplicateEvent->tags->where('type', TagType::Source->value)->pluck('id')->values()->all(),
            'issue_tags' => $duplicateEvent->tags->where('type', TagType::Issue->value)->pluck('id')->values()->all(),
            'references' => $duplicateEvent->references->pluck('id')->values()->all(),
            'speakers' => $this->duplicateSpeakerState($duplicateEvent),
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
    protected function normalizeEventTypeState(mixed $state): array
    {
        if ($state instanceof Collection) {
            return $state
                ->map(fn (EventType|string $eventType): string => $eventType instanceof EventType ? $eventType->value : (string) $eventType)
                ->filter(fn (string $eventType): bool => $eventType !== '')
                ->values()
                ->all();
        }

        if (is_array($state)) {
            return collect($state)
                ->map(fn (mixed $eventType): string => $eventType instanceof EventType ? $eventType->value : (string) $eventType)
                ->filter(fn (string $eventType): bool => $eventType !== '')
                ->values()
                ->all();
        }

        if ($state instanceof EventType) {
            return [$state->value];
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
    protected function duplicateSpeakerState(Event $duplicateEvent): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        return $duplicateEvent->speakers
            ->pluck('id')
            ->map(fn (mixed $speakerId): ?string => is_string($speakerId) && $access->canUseSpeaker($submitter, $speakerId) ? $speakerId : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{role: string, speaker_id: ?string, name: ?string, is_public: bool, notes: ?string}>
     */
    protected function duplicateOtherKeyPeopleState(Event $duplicateEvent): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        return $duplicateEvent->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $keyPerson->role !== EventKeyPersonRole::Speaker)
            ->map(function (EventKeyPerson $keyPerson) use ($access, $submitter): array {
                $speakerId = is_string($keyPerson->speaker_id) && $access->canUseSpeaker($submitter, $keyPerson->speaker_id)
                    ? $keyPerson->speaker_id
                    : null;

                $fallbackName = $speakerId === null
                    ? (filled($keyPerson->name) ? (string) $keyPerson->name : $keyPerson->display_name)
                    : null;

                return [
                    'role' => $keyPerson->role instanceof EventKeyPersonRole ? $keyPerson->role->value : (string) $keyPerson->role,
                    'speaker_id' => $speakerId,
                    'name' => filled($fallbackName) ? (string) $fallbackName : null,
                    'is_public' => (bool) $keyPerson->is_public,
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
        $eventFormat = $duplicateEvent->event_format instanceof EventFormat
            ? $duplicateEvent->event_format->value
            : (is_string($duplicateEvent->event_format) ? $duplicateEvent->event_format : EventFormat::Physical->value);
        $organizerId = is_string($duplicateEvent->organizer_id) ? $duplicateEvent->organizer_id : null;
        $institutionId = is_string($duplicateEvent->institution_id) ? $duplicateEvent->institution_id : null;

        if ($duplicateEvent->organizer_type === Institution::class && $organizerId !== null && $access->canUseInstitution($submitter, $organizerId)) {
            $defaults['organizer_type'] = 'institution';
            $defaults['organizer_institution_id'] = $organizerId;
        }

        if ($duplicateEvent->organizer_type === Speaker::class && $organizerId !== null && $access->canUseSpeaker($submitter, $organizerId)) {
            $defaults['organizer_type'] = 'speaker';
            $defaults['organizer_speaker_id'] = $organizerId;
        }

        if ($eventFormat === EventFormat::Online->value) {
            return $defaults;
        }

        if (filled($duplicateEvent->venue_id)) {
            $defaults['location_same_as_institution'] = false;
            $defaults['location_type'] = 'venue';
            $defaults['location_venue_id'] = $duplicateEvent->venue_id;
            $defaults['location_institution_id'] = null;

            return $defaults;
        }

        if ($institutionId !== null && $access->canUseInstitution($submitter, $institutionId)) {
            $defaults['location_type'] = 'institution';
            $defaults['location_institution_id'] = $institutionId;
            $defaults['location_same_as_institution'] = ($defaults['organizer_type'] ?? null) === 'institution'
                && ($defaults['organizer_institution_id'] ?? null) === $institutionId;
        }

        return $defaults;
    }

    public function parentProgramManagementUrl(): ?string
    {
        $parentEvent = $this->selectedParentEvent();

        return $parentEvent instanceof Event
            ? EventResource::getUrl('view', ['record' => $parentEvent], panel: 'ahli')
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
                ->get(['institutions.id', 'institutions.name', 'institutions.nickname'])
                ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
                ->all());
        }

        return $access->institutionQueryForSubmitter($submitter)
            ->orderBy('name')
            ->get(['institutions.id', 'institutions.name', 'institutions.nickname'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function availableSpeakerOptions(): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        if (! $submitter instanceof User) {
            return Cache::remember('submit_speakers', 60, fn (): array => $access->speakerQueryForSubmitter(null)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                ->all());
        }

        return $access->speakerQueryForSubmitter($submitter)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function assertSubmissionEntitiesAreAccessible(array $validated): void
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $this->submitterUser();

        $organizerType = $validated['organizer_type'] ?? ($this->data['organizer_type'] ?? null);
        $organizerInstitutionId = (string) ($validated['organizer_institution_id'] ?? ($this->data['organizer_institution_id'] ?? ''));
        $organizerSpeakerId = (string) ($validated['organizer_speaker_id'] ?? ($this->data['organizer_speaker_id'] ?? ''));
        $locationInstitutionId = (string) ($validated['location_institution_id'] ?? ($this->data['location_institution_id'] ?? ''));

        if ($organizerType === 'institution' && $organizerInstitutionId !== '') {
            if (! $access->canUseInstitution($submitter, $organizerInstitutionId)) {
                throw ValidationException::withMessages([
                    'data.organizer_institution_id' => __('Anda tidak dibenarkan memilih institusi ini untuk penghantaran majlis.'),
                ]);
            }
        }

        if ($organizerType === 'speaker' && $organizerSpeakerId !== '') {
            if (! $access->canUseSpeaker($submitter, $organizerSpeakerId)) {
                throw ValidationException::withMessages([
                    'data.organizer_speaker_id' => __('Anda tidak dibenarkan memilih penceramah ini untuk penghantaran majlis.'),
                ]);
            }
        }

        $eventFormat = $validated['event_format'] ?? EventFormat::Physical->value;
        $requiresLocationChoice = $organizerType === 'speaker' || ! ($validated['location_same_as_institution'] ?? true);
        $usesLocationInstitution = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && (($validated['location_type'] ?? 'institution') === 'institution');

        if ($usesLocationInstitution && $locationInstitutionId !== '') {
            if (! $access->canUseInstitution($submitter, $locationInstitutionId)) {
                throw ValidationException::withMessages([
                    'data.location_institution_id' => __('Anda tidak dibenarkan memilih institusi lokasi ini.'),
                ]);
            }
        }

        $speakerIds = collect(array_merge(
            (array) ($validated['speakers'] ?? []),
            collect((array) ($validated['other_key_people'] ?? []))
                ->pluck('speaker_id')
                ->all(),
            (array) ($this->data['speakers'] ?? []),
        ))
            ->map(fn (mixed $value): ?string => filled($value) ? (string) $value : null)
            ->filter()
            ->unique()
            ->values();

        foreach ($speakerIds as $speakerId) {
            if (! $access->canUseSpeaker($submitter, $speakerId)) {
                throw ValidationException::withMessages([
                    'data.speakers' => __('Senarai penceramah mengandungi pilihan yang tidak dibenarkan untuk penghantaran ini.'),
                ]);
            }
        }
    }

    protected function hasCommunityEventTypeSelection(mixed $eventTypes): bool
    {
        if ($eventTypes instanceof Collection) {
            $eventTypes = $eventTypes->all();
        }

        if (! is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }

        foreach ($eventTypes as $eventTypeValue) {
            $eventType = $eventTypeValue instanceof EventType
                ? $eventTypeValue
                : EventType::tryFrom((string) $eventTypeValue);

            if ($eventType?->isCommunity()) {
                return true;
            }
        }

        return false;
    }

    protected function eventTypesRequireSpeakers(mixed $eventTypes): bool
    {
        if ($eventTypes instanceof Collection) {
            $eventTypes = $eventTypes->all();
        }

        if (! is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }

        foreach ($eventTypes as $eventTypeValue) {
            $eventType = $eventTypeValue instanceof EventType
                ? $eventTypeValue
                : EventType::tryFrom((string) $eventTypeValue);

            if ($eventType?->requiresSpeakerByDefault()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the starts_at datetime from event_date and prayer_time/custom_time.
     *
     * @param  array{event_date: string, prayer_time: string|EventPrayerTime, custom_time?: string|null, submission_country_id?: int|string|null}  $validated
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

        // Ramadhan dates for common years (approximate)
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

    protected function resolveSubmissionCountryId(mixed $countryId = null): int
    {
        $registry = app(PublicCountryRegistry::class);

        $normalizedCountryId = $registry->resolveCountryId(
            $countryId,
            null,
            null,
            enabledOnly: true,
        );

        if (is_int($normalizedCountryId)) {
            return $normalizedCountryId;
        }

        $currentCountryId = $registry->normalizeCountryId(app(PreferredCountryResolver::class)->resolveId(request()));

        if (is_int($currentCountryId)) {
            return $currentCountryId;
        }

        return $registry->countryIdForKey($registry->defaultKey())
            ?? $registry->countryIdFromIso2('MY')
            ?? 132;
    }

    protected function resolveSubmissionTimezone(mixed $countryId = null): string
    {
        return app(PublicCountryRegistry::class)->defaultTimezoneForCountryId(
            $this->resolveSubmissionCountryId($countryId),
        );
    }

    /**
     * @param  array{submitter_email?: string|null, submitter_phone?: string|null}  $validated
     */
    protected function storeSubmitterContacts(EventSubmission $submission, array $validated): void
    {
        $email = $validated['submitter_email'] ?? null;
        $phone = $validated['submitter_phone'] ?? null;

        if (filled($email)) {
            $submission->contacts()->create([
                'type' => ContactType::Main->value,
                'category' => ContactCategory::Email->value,
                'value' => $email,
                'is_public' => false,
            ]);
        }

        if (filled($phone)) {
            $submission->contacts()->create([
                'type' => ContactType::Main->value,
                'category' => ContactCategory::Phone->value,
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
};
?>

@section('title', __('Hantar Majlis') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'],
])

<style>
    /* Ensure wizard stepper header doesn't clip */
    .fi-sc-wizard-header {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .fi-sc-wizard-header::-webkit-scrollbar {
        height: 3px;
    }

    .fi-sc-wizard-header::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 9999px;
    }

    /* Style grouped select dropdown */
    .fi-dropdown-header {
        font-weight: 600;
        color: #64748b;
        font-size: 0.875rem;
        padding: 0.5rem 0.75rem;
    }

    .fi-dropdown-list {
        padding-left: 0;
    }

    .fi-dropdown-list-item {
        padding-left: 1.5rem !important;
    }

    /* Hide loading indicators by default - Livewire will show them during actual loading */
    .fi-loading-indicator {
        display: none;
    }
</style>

<div class="bg-slate-50 min-h-screen py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="max-w-6xl xl:max-w-7xl mx-auto">
            @if(($parentEvent = $this->selectedParentEvent()) instanceof \App\Models\Event)
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5 shadow-sm">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Parent Program') }}</p>
                            <h2 class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ $parentEvent->title }}</h2>
                            <p class="mt-2 text-sm text-emerald-900/75">
                                {{ __('This submission will be attached as a child event under the selected parent program.') }}
                            </p>
                        </div>
                        @if($parentProgramManagementUrl = $this->parentProgramManagementUrl())
                            <a href="{{ $parentProgramManagementUrl }}"
                                class="inline-flex h-11 items-center justify-center rounded-xl border border-emerald-300 bg-white px-5 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100/70">
                                {{ __('Back to Parent Program') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="font-heading text-4xl font-bold text-slate-900">{{ __('Hantar Majlis Ilmu') }}</h1>
                <p class="text-slate-500 mt-4 text-lg">
                    {{ __('Kongsi majlis ilmu dengan komuniti. Penghantaran anda akan disemak sebelum diterbitkan.') }}
                </p>
            </div>

            {{--
            <div class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Isi Automatik Dengan AI') }}</h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{ __('Muat naik poster, gambar, atau PDF majlis. Kami akan cuba isi borang ini secara automatik dan bawa anda terus ke pratonton.') }}
                </p>

                <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center">
                    <label for="submit-event-source-attachment" class="sr-only">
                        {{ __('Pilih poster, gambar, atau PDF majlis') }}
                    </label>

                    <input id="submit-event-source-attachment" type="file" wire:model="event_source_attachment"
                        accept=".pdf,image/jpeg,image/png,image/webp"
                        aria-describedby="submit-event-source-attachment-help"
                        class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">

                    <x-filament::button type="button" wire:click="extractEventFromMedia" wire:loading.attr="disabled"
                        wire:target="event_source_attachment,extractEventFromMedia" class="whitespace-nowrap">
                        {{ __('Ekstrak Dengan AI') }}
                    </x-filament::button>
                </div>

                @error('event_source_attachment')
                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                @enderror

                <p id="submit-event-source-attachment-help" class="mt-2 text-xs text-slate-500">
                    {{ __('PDF, JPEG, PNG, atau WEBP dibenarkan. Kami akan cuba baca butiran majlis daripada fail ini.') }}
                </p>

                <p wire:loading wire:target="extractEventFromMedia" class="mt-2 text-sm text-primary-600">
                    {{ __('Sedang mengekstrak maklumat daripada fail...') }}
                </p>
            </div>
            --}}

            <form wire:submit="submit" novalidate>
                {{ $this->form }}

                @if(config('services.turnstile.enabled') && filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')))
                    <div class="mt-6 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <p class="mb-3 text-sm font-semibold text-slate-700">{{ __('Pengesahan Keselamatan') }}</p>
                        <p class="mb-3 text-xs text-slate-500">
                            {{ __('Sila sahkan anda bukan robot sebelum menghantar majlis.') }}
                        </p>
                        <input id="submit-event-captcha-token" type="hidden" wire:model.live="data.captcha_token">
                        <div id="submit-event-turnstile" wire:ignore></div>

                        @error('data.captcha_token')
                            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            </form>

            <x-filament-actions::modals />
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            if (window.__submitEventA11yBooted) {
                return;
            }

            window.__submitEventA11yBooted = true;

            const normalizeText = (value) => (value ?? '').replace(/\s+/g, ' ').trim();

            const applySubmitEventAccessibilityFixes = () => {
                document.querySelectorAll('.fi-fo-rich-editor').forEach((field) => {
                    const fieldWrapper = field.closest('[data-field-wrapper]');
                    const label = normalizeText(fieldWrapper?.querySelector('.fi-fo-field-label')?.textContent)
                        .replace(/\*$/, '')
                        .trim();
                    const editor = field.querySelector('.tiptap[contenteditable="true"]');

                    if (editor && label) {
                        editor.setAttribute('aria-label', label);
                        editor.setAttribute('title', label);
                    }
                });

                document.querySelectorAll('.fi-sc-wizard-header-step-btn[role="step"]').forEach((button) => {
                    button.removeAttribute('role');

                    const text = normalizeText(button.innerText);

                    if (text) {
                        button.setAttribute('aria-label', text);
                    }
                });

                document.querySelectorAll('button.fi-select-input-btn').forEach((button) => {
                    const valueText = normalizeText(button.innerText);
                    const labelledByIds = (button.getAttribute('aria-labelledby') ?? '')
                        .split(/\s+/)
                        .filter(Boolean);
                    const valueNode = button.querySelector('.fi-select-input-value-ctn > *')
                        ?? button.querySelector('.fi-select-input-value-ctn');

                    if (valueNode && valueText) {
                        const valueNodeId = valueNode.id || `${button.id}-value`;
                        valueNode.id = valueNodeId;

                        if (! labelledByIds.includes(valueNodeId)) {
                            labelledByIds.push(valueNodeId);
                        }
                    }

                    if (labelledByIds.length > 0) {
                        button.setAttribute('aria-labelledby', labelledByIds.join(' '));
                    }

                    button.removeAttribute('aria-label');
                });
            };

            const boot = () => window.requestAnimationFrame(applySubmitEventAccessibilityFixes);

            document.addEventListener('DOMContentLoaded', boot);
            document.addEventListener('livewire:navigated', boot);

            const observer = new MutationObserver(() => boot());
            observer.observe(document.body, {
                childList: true,
                subtree: true,
            });

            boot();
        })();
    </script>
@endpush

@if(config('services.turnstile.enabled') && filled(config('services.turnstile.site_key')))
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
        <script>
            (() => {
                let isRendered = false;

                const setToken = (token) => {
                    const tokenInput = document.getElementById('submit-event-captcha-token');

                    if (!tokenInput) {
                        return;
                    }

                    tokenInput.value = token;
                    tokenInput.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                };

                const renderTurnstile = () => {
                    const container = document.getElementById('submit-event-turnstile');

                    if (!container || isRendered || typeof window.turnstile === 'undefined') {
                        return;
                    }

                    window.turnstile.render(container, {
                        sitekey: '{{ config('services.turnstile.site_key') }}',
                        callback: (token) => setToken(token),
                        'expired-callback': () => setToken(''),
                        'error-callback': () => setToken(''),
                    });

                    isRendered = true;
                };

                const boot = () => {
                    renderTurnstile();

                    window.setTimeout(renderTurnstile, 400);
                    window.setTimeout(renderTurnstile, 1200);
                };

                document.addEventListener('DOMContentLoaded', boot);
                document.addEventListener('livewire:navigated', boot);
            })();
        </script>
    @endpush
@endif
