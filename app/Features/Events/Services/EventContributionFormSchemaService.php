<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Contributions\Api\SubmissionEntityAccess;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventContributionOptionsCountry;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Api\Filament\EventContributionFormContext;
use App\Features\Events\Api\Filament\EventContributionFormFields;
use App\Features\Events\Api\Filament\EventContributionFormMode;
use App\Features\Events\Api\Filament\EventContributionFormSchema;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Events\Queries\PublicReferenceConstraint;
use App\Features\Events\Support\EventContributionFormRules;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Institutions\Api\Filament\InstitutionQuickAdd;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\Filament\PersonQuickAdd;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\Filament\VenueQuickAdd;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\References\Api\Filament\ReferenceQuickCreate;
use App\Platform\Ui\Forms\Select;
use App\Shared\Cache\SelectionCatalog;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select as FilamentSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Throwable;

/**
 * Canonical public event form definition shared by the submit-event wizard and
 * the contribution update tabs.
 *
 * Field set, order, copy, options and requirements live here only. Entrypoints
 * keep their own shell (wizard steps, tabs, review step, guest contact,
 * CAPTCHA, submit transport) and pass lifecycle differences through the
 * context and the injected create-only dependencies:
 *
 * - create supplies the attached-occurrence selector and the verified Maps
 *   institution quick-add, and owns submission country/timezone, the
 *   past-time guards, published-title prefill and country-mismatch clearing;
 * - update keeps its persisted projection state keys and resolves option
 *   scope from the existing record, keeping selected existing records
 *   selectable even when they are outside the public option set.
 */
final class EventContributionFormSchemaService implements EventContributionFormSchema
{
    /** @var array<string, Event|null> */
    private array $events = [];

    public function __construct(private readonly SubmitEventOptionsProvider $options) {}

    /**
     * @return array<int, Component>
     */
    public function topicComponents(EventContributionFormContext $context): array
    {
        $keys = EventContributionFormFields::keys($context->mode);

        return [
            $this->titleField($context, $keys),
            $this->categoryField($context, $keys),
            $this->domainTopicField($context, $keys),
            Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    $this->taxonomyField($context, $keys, EventTaxonomyCode::Discipline),
                ]),
            RichEditor::make($keys[EventContributionFormFields::DESCRIPTION])
                ->label(__('Keterangan'))
                ->json(! $context->mode->isCreate())
                ->maxLength(5000)
                ->disableToolbarButtons(['table'])
                ->floatingToolbars([])
                ->placeholder(__('Terangkan mengenai majlis, topik yang akan dikupas, dll.')),
            Group::make()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            $this->sourceTopicField($context, $keys),
                            $this->taxonomyField($context, $keys, EventTaxonomyCode::Issue),
                        ]),
                    $this->referencesField($context, $keys),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function scheduleComponents(EventContributionFormContext $context, ?Component $occurrenceSelector = null): array
    {
        $keys = EventContributionFormFields::keys($context->mode);
        $isCreate = $context->mode->isCreate();

        return [
            Section::make(__('Tarikh & Masa'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'md' => 8])
                        ->schema([
                            ...($occurrenceSelector instanceof Component ? [$occurrenceSelector] : []),
                            $this->dateField($context, $keys),
                            Select::make($keys[EventContributionFormFields::PRAYER_TIME])
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
                                ->afterStateUpdatedJs($context->progressUpdateJs)
                                ->afterStateUpdated(function (Get $get, Set $set) use ($isCreate, $keys): void {
                                    if ($isCreate) {
                                        $this->clearIncoherentEndTime($get, $set, $keys);
                                    }
                                })
                                ->options(fn (Get $get): array => EventContributionFormRules::prayerTimeOptions(
                                    $get($keys[EventContributionFormFields::EVENT_DATE]),
                                    $get($keys[EventContributionFormFields::TIMEZONE]),
                                    $this->optionsCountryIso2($get, $context, $keys),
                                ))
                                ->columnSpan(['default' => 1, 'md' => 2]),
                            $this->customTimeField($context, $keys),
                            $this->endTimeField($context, $keys),
                            ...($isCreate ? [Hidden::make('prayer_preview')->dehydrated(false)] : []),
                            ...($isCreate ? [] : [$this->timezoneField($context, $keys)]),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function attendanceComponents(EventContributionFormContext $context): array
    {
        $keys = EventContributionFormFields::keys($context->mode);

        return [
            Section::make(__('Kehadiran'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Select::make($keys[EventContributionFormFields::GENDER])
                                ->native(false)
                                ->label(__('Jantina'))
                                ->required()
                                ->options(EventGenderRestriction::class)
                                ->default(EventGenderRestriction::All)
                                ->afterStateUpdatedJs($context->progressUpdateJs),
                            Select::make($keys[EventContributionFormFields::AGE_GROUP])
                                ->native(false)
                                ->label(__('Peringkat Umur'))
                                ->placeholder(__('Pilih peringkat umur'))
                                ->required()
                                ->options(EventAgeGroup::class)
                                ->closeOnSelect()
                                ->multiple()
                                ->afterStateUpdatedJs(<<<'JS_WRAP'
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
                                JS_WRAP)
                                ->afterStateUpdatedJs($context->progressUpdateJs)
                                ->afterStateUpdated(function (mixed $state, Set $set) use ($keys): void {
                                    $normalizedAgeGroups = EventContributionFormRules::normalizeAgeGroupState($state);
                                    $ageGroups = EventContributionFormRules::normalizeAgeGroupSelection($normalizedAgeGroups);

                                    if ($ageGroups !== $normalizedAgeGroups) {
                                        $set($keys[EventContributionFormFields::AGE_GROUP], $ageGroups);
                                    }

                                    if (EventContributionFormRules::shouldForceChildrenAllowed($ageGroups)) {
                                        $set($keys[EventContributionFormFields::CHILDREN_ALLOWED], true);
                                    }
                                }),
                            Select::make($keys[EventContributionFormFields::LANGUAGES])
                                ->native(false)
                                ->label(__('Bahasa'))
                                ->helperText(__('Bahasa yang akan digunakan dalam majlis.'))
                                ->placeholder(__('Pilih bahasa'))
                                ->closeOnSelect()
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => $this->languageOptions($context))
                                ->afterStateUpdatedJs($context->progressUpdateJs),
                            Toggle::make($keys[EventContributionFormFields::CHILDREN_ALLOWED])
                                ->label(__('Kanak-kanak Dibenarkan'))
                                ->helperText(__('Adakah ibu bapa boleh membawa anak kecil ke majlis ini?'))
                                ->default(true)
                                ->inline(false)
                                ->disabled(fn (Get $get): bool => EventContributionFormRules::shouldForceChildrenAllowed(
                                    $get($keys[EventContributionFormFields::AGE_GROUP]),
                                ))
                                ->extraAlpineAttributes([
                                    'x-bind:disabled' => <<<'JS'
                                        ($get('age_group') || []).includes('children') || ($get('age_group') || []).includes('all_ages')
                                    JS,
                                ])
                                ->dehydrated(),
                            Toggle::make($keys[EventContributionFormFields::IS_MUSLIM_ONLY])
                                ->label(__('Terbuka untuk Muslim Sahaja'))
                                ->helperText(__('Jika tidak ditanda, majlis dianggap terbuka kepada Muslim dan bukan Muslim.'))
                                ->inline(false)
                                ->default(false),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function countryComponents(EventContributionFormContext $context): array
    {
        if (! $context->mode->isCreate()) {
            return [];
        }

        $keys = EventContributionFormFields::keys(EventContributionFormMode::Create);

        return [
            Section::make(__('Negara'))
                ->schema([
                    Select::make(EventContributionFormFields::SUBMISSION_COUNTRY_ID)
                        ->native(false)
                        ->label(__('Country'))
                        ->required()
                        ->options(fn (): array => app(SelectionCatalog::class)->rememberAddressOptions(
                            'countries',
                            static fn (): array => AddressCountry::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all(),
                        ))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdatedJs($context->progressUpdateJs)
                        ->afterStateUpdated(function (Get $get, Set $set) use ($context, $keys): void {
                            $this->syncSubmissionTimezoneForCountry($get, $set, $keys);
                            $this->applyContextualDefaults($get, $set, $keys);
                            $this->clearCountryMismatchedEntitySelections($get, $set, $context);
                        }),
                    $this->timezoneField($context, $keys),
                ]),
        ];
    }

    /**
     * @param  (Closure(FilamentSelect): FilamentSelect)|null  $configureInstitutionSelect
     * @return array<int, Component>
     */
    public function organizerComponents(EventContributionFormContext $context, ?Closure $configureInstitutionSelect = null): array
    {
        $keys = EventContributionFormFields::keys($context->mode);
        $isCreate = $context->mode->isCreate();
        $hasScopedInstitution = $context->scopedInstitutionId !== null;

        return [
            Section::make(__('Format & Pautan'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            Radio::make($keys[EventContributionFormFields::EVENT_FORMAT])
                                ->label(__('Format Majlis'))
                                ->required()
                                ->options(EventFormat::class)
                                ->default(EventFormat::Physical)
                                ->afterStateUpdated(function (Set $set, mixed $state) use ($keys): void {
                                    if ($state === EventFormat::Online || $state === EventFormat::Online->value) {
                                        $set($keys[EventContributionFormFields::SPACE_IDS], []);
                                    }
                                })
                                ->afterStateUpdatedJs("if (\$state === 'online') { \$set('space_ids', []); }")
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $this->disablesEventFormat($value, $get, $context, $keys))
                                ->afterStateUpdatedJs($context->progressUpdateJs)
                                ->inline(),
                            Radio::make($keys[EventContributionFormFields::VISIBILITY])
                                ->label(__('Keterlihatan'))
                                ->required()
                                ->options(EventVisibility::class)
                                ->default(EventVisibility::Public)
                                ->afterStateUpdatedJs($context->progressUpdateJs)
                                ->hidden($isCreate)
                                ->dehydratedWhenHidden()
                                ->inline(),
                            TextInput::make($keys[EventContributionFormFields::EVENT_URL])
                                ->label(__('Pautan Majlis'))
                                ->url()
                                ->rules(['url:http,https'])
                                ->maxLength(255)
                                ->placeholder(__('https://example.com/event')),
                            TextInput::make($keys[EventContributionFormFields::LIVE_URL])
                                ->label(__('Pautan Siaran Langsung'))
                                ->url()
                                ->rules(['url:http,https'])
                                ->maxLength(255)
                                ->placeholder(__('https://youtube.com/...')),
                            TextInput::make($keys[EventContributionFormFields::RECORDING_URL])
                                ->label(__('Pautan Rakaman'))
                                ->url()
                                ->rules(['url:http,https'])
                                ->maxLength(255),
                        ]),
                ]),
            Section::make(__('Penganjur'))
                ->schema([
                    Hidden::make($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID])
                        ->afterStateUpdatedJs($context->progressUpdateJs),
                    Radio::make($keys[EventContributionFormFields::PRIMARY_ORGANIZER_KIND])
                        ->label(__('Jenis Penganjur'))
                        ->required(fn (Get $get): bool => ! $hasScopedInstitution
                            && ! filled($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID])))
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
                        ->afterStateUpdatedJs($context->progressUpdateJs)
                        ->inline()
                        ->visible(! $hasScopedInstitution)
                        ->afterStateUpdated(function (Set $set, ?string $state) use ($keys): void {
                            if ($state !== 'institution') {
                                $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID], null);
                            }

                            if ($state !== 'person') {
                                $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_PERSON_ID], null);
                            }

                            $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID], null);
                            $set($keys[EventContributionFormFields::SPACE_IDS], []);
                        }),
                    ...$this->organizerSelects($context, $keys, $configureInstitutionSelect),
                    Select::make($keys[EventContributionFormFields::SERIES_IDS])
                        ->label(__('Siri'))
                        ->placeholder(__('Pilih siri'))
                        ->helperText($isCreate ? __('Applies when the submission creates a new event.') : null)
                        ->options(fn (): array => $this->seriesOptions($context))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->visible($isCreate ? ! $context->hasEventContainer : true),
                ]),
        ];
    }

    /**
     * @param  (Closure(FilamentSelect): FilamentSelect)|null  $configureInstitutionSelect
     * @return array<int, Component>
     */
    public function locationComponents(EventContributionFormContext $context, ?Closure $configureInstitutionSelect = null): array
    {
        $keys = EventContributionFormFields::keys($context->mode);
        $isCreate = $context->mode->isCreate();
        $hasScopedInstitution = $context->scopedInstitutionId !== null;
        $hasScopedInstitutionJs = $hasScopedInstitution ? 'true' : 'false';

        return [
            Section::make(__('Lokasi'))
                ->visibleJs(<<<'JS'
                            $get('event_format') !== 'online' && ($get('primary_organizer_kind') || $get('primary_organizer_id'))
                            JS)
                ->schema([
                    Toggle::make($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION])
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
                        ->afterStateUpdated(function (Get $get, Set $set, bool $state) use ($keys, $hasScopedInstitution, $isCreate): void {
                            $set($keys[EventContributionFormFields::SPACE_IDS], []);

                            if (! $state) {
                                if (! $isCreate && ! $hasScopedInstitution) {
                                    return;
                                }

                                $set($keys[EventContributionFormFields::LOCATION_TYPE], 'venue');
                                $set($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID], null);

                                return;
                            }

                            $set($keys[EventContributionFormFields::LOCATION_TYPE], 'institution');
                            $set(
                                $keys[EventContributionFormFields::LOCATION_INSTITUTION_ID],
                                $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID]),
                            );
                            $set($keys[EventContributionFormFields::LOCATION_VENUE_ID], null);
                        })
                        ->afterStateUpdatedJs($context->progressUpdateJs),
                    Radio::make($keys[EventContributionFormFields::LOCATION_TYPE])
                        ->label(__('Jenis Lokasi'))
                        ->options([
                            'institution' => __('Institusi'),
                            'venue' => __('Tempat'),
                        ])
                        ->inline()
                        ->default('institution')
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state) use ($keys, $isCreate): void {
                            $set($keys[EventContributionFormFields::SPACE_IDS], []);

                            if ($isCreate) {
                                return;
                            }

                            if ($state === 'venue') {
                                $set($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID], null);

                                return;
                            }

                            if ($state === 'institution') {
                                $set($keys[EventContributionFormFields::LOCATION_VENUE_ID], null);
                            }
                        })
                        ->afterStateUpdatedJs("\$set('space_ids', [])")
                        ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution'))")
                        ->required(fn (Get $get): bool => $this->requiresSeparateLocationChoice($get, $keys)
                            && ! $this->isOnlineEventFormat($get($keys[EventContributionFormFields::EVENT_FORMAT])))
                        ->afterStateUpdatedJs($context->progressUpdateJs),
                    $this->withConfiguredSelect(
                        Select::make($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID])
                            ->native(false)
                            ->label(__('Institusi'))
                            ->options(fn (Get $get): array => $this->institutionOptions($get, $context))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set($keys[EventContributionFormFields::SPACE_IDS], []))
                            ->afterStateUpdatedJs("\$set('space_ids', [])")
                            ->visibleJs("! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'institution'")
                            ->required(fn (Get $get): bool => ! $this->isOnlineEventFormat($get($keys[EventContributionFormFields::EVENT_FORMAT]))
                                && $this->requiresSeparateLocationChoice($get, $keys)
                                && $get($keys[EventContributionFormFields::LOCATION_TYPE]) === 'institution')
                            ->afterStateUpdatedJs($context->progressUpdateJs),
                        $configureInstitutionSelect,
                    ),
                    Select::make($keys[EventContributionFormFields::LOCATION_VENUE_ID])
                        ->native(false)
                        ->label(__('Lokasi'))
                        ->options(fn (Get $get): array => $this->venueOptions($get, $context))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set($keys[EventContributionFormFields::SPACE_IDS], []))
                        ->afterStateUpdatedJs("\$set('space_ids', [])")
                        ->visibleJs("({$hasScopedInstitutionJs} && !\$get('location_same_as_institution')) || (! {$hasScopedInstitutionJs} && (\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && \$get('location_type') === 'venue')")
                        ->required(fn (Get $get): bool => ! $this->isOnlineEventFormat($get($keys[EventContributionFormFields::EVENT_FORMAT]))
                            && $this->requiresSeparateLocationChoice($get, $keys)
                            && $get($keys[EventContributionFormFields::LOCATION_TYPE]) === 'venue')
                        ->afterStateUpdatedJs($context->progressUpdateJs)
                        ->createOptionForm(app(VenueQuickAdd::class)->formComponents(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, Schema $schema): string => app(VenueQuickAdd::class)->createOption($data, $schema)),
                    Select::make($keys[EventContributionFormFields::SPACE_IDS])
                        ->native(false)
                        ->label(__('Ruang'))
                        ->helperText(__('Pilih satu atau lebih ruang (cth: Dewan Utama, Ruang Solat).'))
                        ->placeholder(__('Pilih ruang…'))
                        ->searchable()
                        ->preload()
                        ->multiple()
                        ->visibleJs("({$hasScopedInstitutionJs} && (\$get('location_same_as_institution') !== false)) || (\$get('primary_organizer_kind') === 'institution' && (\$get('location_same_as_institution') !== false)) || ((\$get('primary_organizer_kind') === 'person' || !\$get('location_same_as_institution')) && (\$get('location_type') === 'institution' || (\$get('location_type') === 'venue' && \$get('location_venue_id'))))")
                        ->options(function (Get $get) use ($keys): array {
                            $venueId = $this->normalizedString($get($keys[EventContributionFormFields::LOCATION_VENUE_ID]));

                            if ($venueId !== null && $get($keys[EventContributionFormFields::LOCATION_TYPE]) === 'venue') {
                                return app(SpaceEligibility::class)->venueOptions($venueId, activeOnly: true);
                            }

                            $institutionId = $this->resolvedLocationInstitutionId($get, $keys);

                            return $institutionId !== null
                                ? app(SpaceEligibility::class)->institutionOptions($institutionId, activeOnly: true)
                                : app(SpaceEligibility::class)->catalogOptions(activeOnly: true);
                        }),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function speakerComponents(EventContributionFormContext $context): array
    {
        $keys = EventContributionFormFields::keys($context->mode);

        return [
            Section::make(__('Penceramah'))
                ->schema([
                    Select::make($keys[EventContributionFormFields::PERSONS])
                        ->native(false)
                        ->label(__('Pilih Penceramah'))
                        ->placeholder(__('Pilih Penceramah'))
                        ->required(fn (Get $get): bool => EventContributionFormRules::categoriesRequirePersons(
                            $get($keys[EventContributionFormFields::EVENT_CATEGORY_IDS]),
                        ))
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->personOptions($context))
                        ->helperText(fn (Get $get): string => EventContributionFormRules::categoriesRequirePersons(
                            $get($keys[EventContributionFormFields::EVENT_CATEGORY_IDS]),
                        )
                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.'))
                        ->afterStateUpdatedJs($context->progressUpdateJs)
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->personLabel($context, $value))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->personLabels($context, $values))
                        ->createOptionForm(app(PersonQuickAdd::class)->formComponents())
                        ->createOptionUsing(fn (array $data, Schema $schema): string => app(PersonQuickAdd::class)->createOption($data, $schema)),
                    Repeater::make($keys[EventContributionFormFields::OTHER_KEY_PEOPLE])
                        ->label(__('Peranan Lain'))
                        ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                        ->default([])
                        ->schema([
                            Select::make('role_code')
                                ->label(__('Peranan'))
                                ->required()
                                ->options(EventKeyPersonRole::nonSpeakerOptions())
                                ->native(false),
                            Select::make('involveable_id')
                                ->native(false)
                                ->label(__('Pautkan Profil Penceramah'))
                                ->options(fn (): array => $this->personOptions($context))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $set('display_name', null);
                                    $set('involveable_type', filled($state) ? 'person' : null);
                                })
                                ->afterStateUpdatedJs(<<<'JS'
                                                    $set('display_name', null)
                                                    $set('involveable_type', $state ? 'person' : null)
                                                    JS)
                                ->getOptionLabelUsing(fn (mixed $value): ?string => $this->personLabel($context, $value))
                                ->createOptionForm(app(PersonQuickAdd::class)->formComponents())
                                ->createOptionUsing(fn (array $data, Schema $schema): string => app(PersonQuickAdd::class)->createOption($data, $schema)),
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
                                ->dehydrated(fn (Get $get): bool => ! $context->mode->isCreate() || blank($get('involveable_id')))
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
                        ->afterStateUpdatedJs($context->progressUpdateJs)
                        ->addActionLabel(__('Tambah Peranan'))
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** @param array<string, string> $keys */
    private function titleField(EventContributionFormContext $context, array $keys): Select
    {
        return Select::make($keys[EventContributionFormFields::TITLE])
            ->native(false)
            ->label(__('Tajuk Majlis'))
            ->required()
            ->rules(['max:255'])
            ->searchable()
            ->allowHtml()
            ->live()
            ->afterStateUpdatedJs($context->progressUpdateJs)
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
            ->afterStateUpdated(function (mixed $state, Set $set) use ($context, $keys): void {
                if (is_string($state) && str_starts_with($state, '__quick_add__')) {
                    $state = substr($state, strlen('__quick_add__'));
                    $set($keys[EventContributionFormFields::TITLE], $state);
                }

                if (! $context->mode->isCreate() || ! is_string($state) || blank($state)) {
                    return;
                }

                $this->applyPublishedEventDefaults($state, $set, $keys);
            })
            ->placeholder(__('Cari atau masukkan tajuk majlis...'));
    }

    /** @param array<string, string> $keys */
    private function categoryField(EventContributionFormContext $context, array $keys): Select
    {
        return Select::make($keys[EventContributionFormFields::EVENT_CATEGORY_IDS])
            ->label(__('Jenis Majlis'))
            ->placeholder(__('Pilih kategori…'))
            ->required()
            ->multiple(! $context->mode->isCreate())
            ->closeOnSelect(! $context->mode->isCreate())
            ->live()
            ->afterStateUpdatedJs($context->progressUpdateJs)
            ->afterStateUpdated(function (mixed $state, Set $set, Get $get) use ($context, $keys): void {
                if (EventContributionFormRules::hasCommunityCategorySelection($state)) {
                    $set($keys[EventContributionFormFields::EVENT_FORMAT], EventFormat::Physical->value);
                }

                if ($context->mode->isCreate()) {
                    $this->applyContextualDefaults($get, $set, $keys);
                }
            })
            ->options(app(EventCategoryCatalog::class)->options())
            ->preload()
            ->native(false)
            ->dynamicOptions(false);
    }

    /** @param array<string, string> $keys */
    private function domainTopicField(EventContributionFormContext $context, array $keys): Select
    {
        return Select::make($keys[EventContributionFormFields::DOMAIN_TAGS])
            ->label(__('Topik / bidang'))
            ->helperText(__('Wajib dipilih. Bidang ini menentukan soalan tambahan yang akan dipaparkan.'))
            ->placeholder(__('Pilih topik…'))
            ->required()
            ->multiple()
            ->closeOnSelect()
            ->live()
            ->afterStateUpdatedJs($context->progressUpdateJs)
            ->searchable(false)
            ->preload()
            ->native(false)
            ->dynamicOptions(false)
            ->options(fn (): array => $this->options->tagOptions(
                type: EventTaxonomyCode::Domain,
                cachePrefix: $context->mode->isCreate() ? 'submit_tags_domain' : null,
            ))
            ->afterStateUpdated(function (mixed $state, Set $set, Get $get) use ($context, $keys): void {
                if ($context->mode->isCreate()) {
                    $this->applyContextualDefaults($get, $set, $keys);
                    $set($keys[EventContributionFormFields::DISCIPLINE_TAGS], []);
                }
            });
    }

    /** @param array<string, string> $keys */
    private function taxonomyField(EventContributionFormContext $context, array $keys, EventTaxonomyCode $type): Select
    {
        $isDiscipline = $type === EventTaxonomyCode::Discipline;
        $quickAddKey = $isDiscipline ? 'discipline_tags' : 'issue_tags';

        $field = Select::make($keys[$isDiscipline
            ? EventContributionFormFields::DISCIPLINE_TAGS
            : EventContributionFormFields::ISSUE_TAGS])
            ->native(false)
            ->label($isDiscipline ? __('Topik lebih khusus') : __('Tema / Isu'))
            ->helperText($isDiscipline
                ? __('Contoh: Tafsir, Matematik, atau Machine Learning.')
                : __('Pilih tema supaya mudah dicari.'))
            ->placeholder($isDiscipline
                ? __('Pilih atau taip untuk tambah bidang…')
                : __('Pilih atau taip untuk tambah tema…'))
            ->multiple()
            ->searchable()
            ->preload()
            ->allowHtml()
            ->options(fn (Get $get): array => array_map(e(...), $this->options->taxonomyTermOptionsForDomains(
                $type,
                EventContributionFormRules::selectedIds($get($keys[EventContributionFormFields::DOMAIN_TAGS])),
            )))
            ->getSearchResultsUsing(function (string $search, ?Get $get = null) use ($type, $keys): array {
                if (blank($search)) {
                    return [];
                }

                $results = $this->options->taxonomyTermOptionsForDomains(
                    $type,
                    $get instanceof Get ? EventContributionFormRules::selectedIds($get($keys[EventContributionFormFields::DOMAIN_TAGS])) : [],
                    $search,
                );

                return ["__quick_add__{$search}" => "<span class='text-primary-600'>+ ".e(__('Tambah'))." '".e($search)."'</span>"] + array_map(e(...), $results);
            })
            ->getOptionLabelsUsing(fn (array $values): array => $this->taxonomyLabels($values))
            ->afterStateUpdatedJs(<<<JS
                if (Array.isArray(\$state)) {
                    const hasQuickAdd = \$state.some(v => typeof v === 'string' && v.startsWith('__quick_add__'));
                    if (hasQuickAdd) {
                        const cleaned = \$state.map(v => (typeof v === 'string' && v.startsWith('__quick_add__')) ? v.substring(13) : v);
                        \$set('{$quickAddKey}', cleaned);
                        \$nextTick(() => {
                            const wrapper = \$el.querySelector('[wire\\\\:ignore]');
                            if (wrapper) {
                                Alpine.\$data(wrapper)?.select?.closeDropdown();
                            }
                        });
                    }
                }
                JS);

        if ($context->mode->isCreate()) {
            return $field;
        }

        return $field
            ->createOptionForm([
                TextInput::make('name')
                    ->label($isDiscipline ? __('Nama Bidang') : __('Nama Tema'))
                    ->required()
                    ->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): string => EventContributionFormRules::ensureTaxonomyTerm($type, $data));
    }

    /** @param array<string, string> $keys */
    private function sourceTopicField(EventContributionFormContext $context, array $keys): Select
    {
        return Select::make($keys[EventContributionFormFields::SOURCE_TAGS])
            ->visible(fn (Get $get): bool => ! $context->mode->isCreate()
                || EventContributionFormRules::hasAgamaKerohanianTopic(
                    $get($keys[EventContributionFormFields::DOMAIN_TAGS]),
                ))
            ->closeOnSelect()
            ->label(__('Sumber Utama'))
            ->placeholder(__('Pilih sumber…'))
            ->multiple()
            ->preload()
            ->searchable(false)
            ->native(false)
            ->getOptionLabelsUsing(fn (array $values): array => $this->taxonomyLabels($values))
            ->options(fn (): array => $this->options->tagOptions(
                type: EventTaxonomyCode::Source,
                cachePrefix: $context->mode->isCreate() ? 'submit_tags_source' : null,
            ));
    }

    /** @param array<string, string> $keys */
    private function referencesField(EventContributionFormContext $context, array $keys): Select
    {
        $field = Select::make($keys[EventContributionFormFields::REFERENCES])
            ->visible(fn (Get $get): bool => ! $context->mode->isCreate()
                || EventContributionFormRules::hasAgamaKerohanianTopic(
                    $get($keys[EventContributionFormFields::DOMAIN_TAGS]),
                ))
            ->label(__('Rujukan Kitab'))
            ->helperText(__('Kitab atau buku rujukan yang digunakan.'))
            ->placeholder(__('Cari atau pilih rujukan…'))
            ->multiple()
            ->closeOnSelect()
            ->searchable()
            ->native(false)
            ->getSearchResultsUsing(fn (string $search): array => app(ReferenceQuickCreate::class)->searchReferenceOptions($search))
            ->getOptionLabelsUsing(fn (array $values): array => app(ReferenceQuickCreate::class)->selectedReferenceLabels($values))
            ->createOptionForm(app(ReferenceQuickCreate::class)->quickCreateSections())
            ->createOptionUsing(fn (array $data, Schema $schema): string => app(ReferenceQuickCreate::class)->createPendingReference($data, $schema));

        if ($context->mode->isCreate()) {
            $field->relationship(
                'references',
                'title',
                fn (Builder $query) => app(PublicReferenceConstraint::class)->apply($query)->with('parentReference.parentReference'),
            );
        }

        return $field;
    }

    /** @param array<string, string> $keys */
    private function dateField(EventContributionFormContext $context, array $keys): DatePicker
    {
        $field = DatePicker::make($keys[EventContributionFormFields::EVENT_DATE])
            ->label(__('Tarikh'))
            ->required()
            ->native()
            ->live()
            ->afterStateUpdatedJs($context->progressUpdateJs)
            ->columnSpan(['default' => 1, 'md' => 2]);

        if (! $context->mode->isCreate()) {
            return $field->afterStateUpdated(function (Set $set) use ($keys): void {
                $set($keys[EventContributionFormFields::PRAYER_TIME], null);
            });
        }

        return $field
            ->minDate(fn (Get $get): string => Carbon::now(self::resolveSubmissionTimezone(
                $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
                $get($keys[EventContributionFormFields::TIMEZONE]),
            ))->toDateString())
            ->afterStateUpdated(function (Get $get, Set $set) use ($keys): void {
                $this->applyContextualDefaults($get, $set, $keys);
                $this->clearIncoherentEndTime($get, $set, $keys);
            });
    }

    /** @param array<string, string> $keys */
    private function customTimeField(EventContributionFormContext $context, array $keys): TimePicker
    {
        $field = TimePicker::make($keys[EventContributionFormFields::CUSTOM_TIME])
            ->label(__('Masa Mula'))
            ->helperText(__('Pilih masa mula majlis'))
            ->timezone('UTC')
            ->native()
            ->seconds(false)
            ->minutesStep(5)
            ->afterStateUpdatedJs($context->progressUpdateJs)
            ->visible(fn (Get $get): bool => EventContributionFormRules::isPrayerTime(
                $get($keys[EventContributionFormFields::PRAYER_TIME]),
                EventPrayerTime::LainWaktu,
            ))
            ->required(fn (Get $get): bool => EventContributionFormRules::isPrayerTime(
                $get($keys[EventContributionFormFields::PRAYER_TIME]),
                EventPrayerTime::LainWaktu,
            ))
            ->markAsRequired()
            ->columnSpan(['default' => 1, 'md' => 2]);

        if (! $context->mode->isCreate()) {
            return $field;
        }

        return $field
            ->afterStateUpdatedJs($this->customTimeWatcherJs())
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $keys): void {
                $eventDate = $get($keys[EventContributionFormFields::EVENT_DATE]);

                if (! $eventDate || ! $value) {
                    return;
                }

                $timezone = self::resolveSubmissionTimezone(
                    $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
                    $get($keys[EventContributionFormFields::TIMEZONE]),
                );
                $now = Carbon::now($timezone);

                try {
                    $eventDay = Carbon::parse($eventDate, $timezone)->startOfDay();
                } catch (Throwable) {
                    return;
                }

                if (! $eventDay->isSameDay($now)) {
                    return;
                }

                $timeParts = explode(':', (string) $value);
                $selectedTime = $eventDay->copy()
                    ->setHour((int) $timeParts[0])
                    ->setMinute((int) ($timeParts[1] ?? 0));

                if ($selectedTime->lessThan($now)) {
                    $fail(__('Masa yang dipilih tidak boleh pada masa lalu untuk majlis hari ini.'));
                }
            });
    }

    /** @param array<string, string> $keys */
    private function endTimeField(EventContributionFormContext $context, array $keys): TimePicker
    {
        $field = TimePicker::make($keys[EventContributionFormFields::END_TIME])
            ->label(__('Masa Akhir'))
            ->helperText(__('Pilihan: Bila majlis dijangka tamat.'))
            ->timezone('UTC')
            ->native()
            ->seconds(false)
            ->minutesStep(5)
            ->columnSpan(['default' => 1, 'md' => 2]);

        if (! $context->mode->isCreate()) {
            return $field;
        }

        return $field
            ->afterStateUpdatedJs($this->endTimeWatcherJs())
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $keys): void {
                if (! $value) {
                    return;
                }

                $startTime = $this->resolveStartTimeForComparison(
                    $get($keys[EventContributionFormFields::PRAYER_TIME]),
                    $get($keys[EventContributionFormFields::CUSTOM_TIME]),
                    $this->previewStartsForComparison($get('prayer_preview')),
                    $this->comparisonFormState($get, $keys),
                );

                if ($startTime === null) {
                    return;
                }

                if ($this->endTimePrecedesStartTime((string) $value, $startTime)) {
                    $fail(__('Masa akhir mestilah selepas masa mula.'));
                }
            });
    }

    private function customTimeWatcherJs(): string
    {
        return str_replace(
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
        );
    }

    private function endTimeWatcherJs(): string
    {
        return str_replace(
            '__END_TIME_VALIDATION_MESSAGE__',
            Js::from(__('Masa akhir mestilah selepas masa mula.'))->toHtml(),
            <<<'JS'
                const customTime = $get('custom_time');
                const endTime = $state;
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
        );
    }

    /** @param array<string, string> $keys */
    private function timezoneField(EventContributionFormContext $context, array $keys): Select
    {
        if (! $context->mode->isCreate()) {
            return Select::make($keys[EventContributionFormFields::TIMEZONE])
                ->label(__('Timezone'))
                ->options(static function (): array {
                    $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

                    return array_combine($identifiers, $identifiers);
                })
                ->searchable()
                ->rules(['timezone'])
                ->required()
                ->live()
                ->columnSpanFull();
        }

        return Select::make($keys[EventContributionFormFields::TIMEZONE])
            ->native(false)
            ->label(__('Submission timezone'))
            ->helperText(__('Select the event timezone for this country.'))
            ->placeholder(__('Pilih zon waktu…'))
            ->options(fn (Get $get): array => self::submissionTimezoneOptions(
                $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
            ))
            ->searchable()
            ->preload()
            ->live()
            ->visible(fn (Get $get): bool => self::isSubmissionTimezoneRequired($get(EventContributionFormFields::SUBMISSION_COUNTRY_ID)))
            ->required(fn (Get $get): bool => self::isSubmissionTimezoneRequired($get(EventContributionFormFields::SUBMISSION_COUNTRY_ID)))
            ->dehydratedWhenHidden()
            ->afterStateUpdatedJs($context->progressUpdateJs)
            ->afterStateUpdated(function (Get $get, Set $set) use ($keys): void {
                $this->applyContextualDefaults($get, $set, $keys);
            });
    }

    /**
     * @param  (Closure(FilamentSelect): FilamentSelect)|null  $configureInstitutionSelect
     * @param  array<string, string>  $keys
     * @return array<int, Component>
     */
    private function organizerSelects(EventContributionFormContext $context, array $keys, ?Closure $configureInstitutionSelect): array
    {
        $hasScopedInstitution = $context->scopedInstitutionId !== null;
        $hasScopedInstitutionJs = $hasScopedInstitution ? 'true' : 'false';

        return [
            $this->withConfiguredSelect(
                Select::make($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID])
                    ->native(false)
                    ->label(__('Institusi'))
                    ->options(fn (Get $get): array => $this->institutionOptions($get, $context))
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
                    ->afterStateUpdatedJs($context->progressUpdateJs)
                    ->required(fn (Get $get): bool => EventContributionFormRules::selectedPrimaryOrganizerKind(
                        $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_KIND]),
                        $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
                    ) === 'institution' && ! filled($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID])))
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($keys): void {
                        $organizerId = is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null;

                        $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID], $organizerId);

                        if ((bool) $get($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION])) {
                            $set($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID], $organizerId);
                            $set($keys[EventContributionFormFields::LOCATION_VENUE_ID], null);
                            $set($keys[EventContributionFormFields::SPACE_IDS], []);
                        }
                    }),
                $configureInstitutionSelect,
            ),
            Select::make($keys[EventContributionFormFields::PRIMARY_ORGANIZER_PERSON_ID])
                ->native(false)
                ->label(__('Penceramah'))
                ->options(fn (): array => $this->personOptions($context))
                ->searchable()
                ->preload()
                ->visibleJs("! {$hasScopedInstitutionJs} && \$get('primary_organizer_kind') === 'person'")
                ->extraAlpineAttributes([
                    'x-bind:required' => <<<'JS'
                        $get('primary_organizer_kind') === 'person' && ! $get('primary_organizer_id')
                    JS,
                ])
                ->required(fn (Get $get): bool => EventContributionFormRules::selectedPrimaryOrganizerKind(
                    $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_KIND]),
                    $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
                ) === 'person' && ! filled($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID])))
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($keys): void {
                    $set(
                        $keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID],
                        is_scalar($state) && trim((string) $state) !== '' ? trim((string) $state) : null,
                    );

                    if (! is_scalar($state) || trim((string) $state) === '') {
                        return;
                    }

                    $personKey = $keys[EventContributionFormFields::PERSONS];
                    $currentPersons = EventContributionFormRules::normalizeStringList($get($personKey));

                    if (! in_array((string) $state, $currentPersons, true)) {
                        $currentPersons[] = (string) $state;
                        $set($personKey, $currentPersons);
                    }
                })
                ->afterStateUpdatedJs(<<<JS
                    \$set('primary_organizer_id', \$state || null)

                    if (\$state) {
                        const currentPersons = \$get('{$keys[EventContributionFormFields::PERSONS]}') || []
                        if (!currentPersons.includes(\$state)) {
                            \$set('{$keys[EventContributionFormFields::PERSONS]}', [...currentPersons, \$state])
                        }
                    }
                    JS)
                ->afterStateUpdatedJs($context->progressUpdateJs)
                ->getOptionLabelUsing(fn (mixed $value): ?string => $this->personLabel($context, $value))
                ->createOptionForm(app(PersonQuickAdd::class)->formComponents())
                ->createOptionUsing(fn (array $data, Schema $schema): string => app(PersonQuickAdd::class)->createOption($data, $schema)),
        ];
    }

    /** @param  (Closure(FilamentSelect): FilamentSelect)|null  $configure */
    private function withConfiguredSelect(Select $select, ?Closure $configure): FilamentSelect
    {
        $select = $select
            ->createOptionForm(app(InstitutionQuickAdd::class)->formComponents(includeLocationPicker: true))
            ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => app(InstitutionQuickAdd::class)->createOption($data, $schema));

        return $configure instanceof Closure ? $configure($select) : $select;
    }

    /** @param array<string, string> $keys */
    private function applyPublishedEventDefaults(string $title, Set $set, array $keys): void
    {
        $existingEvent = Event::query()
            ->where('title', $title)
            ->where('status', 'approved')
            ->where('visibility', EventVisibility::Public->value)
            ->whereNotNull('published_at')
            ->with(['classifications', 'references'])
            ->latest()
            ->first();

        if (! $existingEvent instanceof Event) {
            return;
        }

        $termsByTaxonomy = $existingEvent->classifications->groupBy('taxonomy_code');

        $set(
            $keys[EventContributionFormFields::EVENT_CATEGORY_IDS],
            $termsByTaxonomy->get('event_category', collect())->pluck('event_term_id')->filter()->values()->first(),
        );

        foreach ([
            EventContributionFormFields::DOMAIN_TAGS => EventTaxonomyCode::Domain->value,
            EventContributionFormFields::DISCIPLINE_TAGS => EventTaxonomyCode::Discipline->value,
            EventContributionFormFields::SOURCE_TAGS => EventTaxonomyCode::Source->value,
            EventContributionFormFields::ISSUE_TAGS => EventTaxonomyCode::Issue->value,
        ] as $field => $taxonomyCode) {
            if (! $termsByTaxonomy->has($taxonomyCode)) {
                continue;
            }

            $set(
                $keys[$field],
                $termsByTaxonomy->get($taxonomyCode)->pluck('event_term_id')->filter()->values()->all(),
            );
        }

        if ($existingEvent->references->isNotEmpty()) {
            $set(
                $keys[EventContributionFormFields::REFERENCES],
                $existingEvent->references->pluck('id')->filter()->values()->all(),
            );
        }
    }

    /** @param array<string, string> $keys */
    private function applyContextualDefaults(Get $get, Set $set, array $keys): void
    {
        if (! $this->isPrayerTimeAvailable(
            $get($keys[EventContributionFormFields::PRAYER_TIME]),
            $get($keys[EventContributionFormFields::EVENT_DATE]),
            $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
            $get($keys[EventContributionFormFields::TIMEZONE]),
        )) {
            $set($keys[EventContributionFormFields::PRAYER_TIME], null);
            $set($keys[EventContributionFormFields::CUSTOM_TIME], null);
            $set($keys[EventContributionFormFields::END_TIME], null);
        }

        if (blank($get($keys[EventContributionFormFields::PRAYER_TIME]))) {
            $set($keys[EventContributionFormFields::PRAYER_TIME], EventPrayerTime::LainWaktu->value);
        }

        if (
            EventContributionFormRules::isPrayerTime($get($keys[EventContributionFormFields::PRAYER_TIME]), EventPrayerTime::LainWaktu)
            && blank($get($keys[EventContributionFormFields::CUSTOM_TIME]))
        ) {
            $set($keys[EventContributionFormFields::CUSTOM_TIME], EventContributionFormFields::DEFAULT_CUSTOM_TIME);
        }
    }

    /** @param array<string, string> $keys */
    private function clearIncoherentEndTime(Get $get, Set $set, array $keys): void
    {
        $endTime = $get($keys[EventContributionFormFields::END_TIME]);

        if (! $endTime) {
            return;
        }

        $startTime = $this->resolveStartTimeForComparison(
            $get($keys[EventContributionFormFields::PRAYER_TIME]),
            $get($keys[EventContributionFormFields::CUSTOM_TIME]),
            $this->previewStartsForComparison($get('prayer_preview')),
            $this->comparisonFormState($get, $keys),
        );

        if ($startTime === null) {
            return;
        }

        if ($this->endTimePrecedesStartTime((string) $endTime, $startTime)) {
            $set($keys[EventContributionFormFields::END_TIME], null);
        }
    }

    /**
     * @param  array<string, string|null>|null  $previewStarts
     * @param  array<string, mixed>|null  $formState
     */
    private function resolveStartTimeForComparison(mixed $prayerTimeValue, mixed $customTime, ?array $previewStarts = null, ?array $formState = null): ?string
    {
        return app(SubmissionTimingPolicy::class)->resolveStartTimeForComparison($prayerTimeValue, $customTime, $previewStarts, $formState);
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    private function comparisonFormState(Get $get, array $keys): array
    {
        return [
            'event_date' => $get($keys[EventContributionFormFields::EVENT_DATE]),
            'submission_country_id' => $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
            'submission_timezone' => $get($keys[EventContributionFormFields::TIMEZONE]),
            'event_format' => $get($keys[EventContributionFormFields::EVENT_FORMAT]),
            'location_type' => $get($keys[EventContributionFormFields::LOCATION_TYPE]),
            'location_institution_id' => $get($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID]),
            'location_venue_id' => $get($keys[EventContributionFormFields::LOCATION_VENUE_ID]),
            'location_same_as_institution' => $get($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION]),
            'primary_organizer_id' => $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
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

    /** @param array<string, string> $keys */
    private function syncSubmissionTimezoneForCountry(Get $get, Set $set, array $keys): void
    {
        $policy = app(SubmissionTimingPolicy::class);
        $timezones = $policy->countryTimezones(
            $policy->resolveSubmissionCountryId($get(EventContributionFormFields::SUBMISSION_COUNTRY_ID)),
        );

        $set($keys[EventContributionFormFields::TIMEZONE], count($timezones) === 1 ? $timezones[0] : null);
    }

    private function clearCountryMismatchedEntitySelections(Get $get, Set $set, EventContributionFormContext $context): void
    {
        $countryId = $this->resolvedSubmissionCountryId($get);

        if ($countryId === null) {
            return;
        }

        $keys = EventContributionFormFields::keys(EventContributionFormMode::Create);
        $access = app(SubmissionEntityAccess::class);

        if ($context->scopedInstitutionId === null) {
            $organizerInstitutionId = $this->normalizeNullableUuid($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID]));

            if ($organizerInstitutionId !== null && ! $access->institutionBelongsToCountry($organizerInstitutionId, $countryId)) {
                $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID], null);
                $set($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID], null);
            }

            $locationInstitutionId = $this->normalizeNullableUuid($get($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID]));

            if ($locationInstitutionId !== null && ! $access->institutionBelongsToCountry($locationInstitutionId, $countryId)) {
                $set($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID], null);
                $set($keys[EventContributionFormFields::SPACE_IDS], []);
            }
        }

        $locationVenueId = $this->normalizeNullableUuid($get($keys[EventContributionFormFields::LOCATION_VENUE_ID]));

        if ($locationVenueId !== null && ! $access->venueBelongsToCountry($locationVenueId, $countryId)) {
            $set($keys[EventContributionFormFields::LOCATION_VENUE_ID], null);
            $set($keys[EventContributionFormFields::SPACE_IDS], []);
        }
    }

    /** @param array<string, string> $keys */
    private function optionsCountryIso2(Get $get, EventContributionFormContext $context, array $keys): ?string
    {
        if ($context->mode->isCreate()) {
            return app(SubmissionTimingPolicy::class)->countryIso2ForId(
                $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
            );
        }

        return app(EventContributionOptionsCountry::class)->forFormState(
            $this->optionsCountryState($get, $keys),
            $context->eventId,
        );
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    private function optionsCountryState(Get $get, array $keys): array
    {
        return [
            'event_format' => $get($keys[EventContributionFormFields::EVENT_FORMAT]),
            'primary_organizer_id' => $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
            'location_same_as_institution' => $get($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION]),
            'location_type' => $get($keys[EventContributionFormFields::LOCATION_TYPE]),
            'location_institution_id' => $get($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID]),
            'location_venue_id' => $get($keys[EventContributionFormFields::LOCATION_VENUE_ID]),
            'space_id' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function institutionOptions(Get $get, EventContributionFormContext $context): array
    {
        if ($context->mode->isCreate()) {
            return $this->options->institutionOptions(
                $context->submitter,
                $this->resolvedSubmissionCountryId($get),
                $context->scopedInstitutionId,
            );
        }

        return $this->withSelectedLabels(app(InstitutionLookup::class)->visibleDisplayNameOptions(), EventContributionFormRules::normalizeStringList([
            $this->eventFor($context)?->primaryOrganizerInvolvement?->involveable_id,
            $this->eventFor($context)?->institution_id,
        ]), static fn (array $ids): array => app(InstitutionLookup::class)->displayNamesByIds($ids));
    }

    /**
     * @return array<string, string>
     */
    private function venueOptions(Get $get, EventContributionFormContext $context): array
    {
        if ($context->mode->isCreate()) {
            return $this->options->venueOptions(
                $this->resolvedSubmissionCountryId($get),
            );
        }

        return $this->withSelectedLabels(app(PlaceCatalog::class)->venueOptionsForStatuses(['verified', 'pending']), EventContributionFormRules::normalizeStringList([
            $this->eventFor($context)?->default_venue_id,
        ]), static fn (array $ids): array => app(PlaceCatalog::class)->venueNamesByIds($ids));
    }

    /**
     * @return array<string, string>
     */
    private function personOptions(EventContributionFormContext $context): array
    {
        if ($context->mode->isCreate()) {
            return $this->options->personOptions($context->submitter);
        }

        return $this->withSelectedLabels(app(PersonLookup::class)->submissionNameOptions(), $this->selectedPersonIds($context), static fn (array $ids): array => app(PersonLookup::class)->namesByIds($ids));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private function personLabels(EventContributionFormContext $context, array $values): array
    {
        $values = array_values(array_filter($values, is_string(...)));

        if ($values === []) {
            return [];
        }

        if ($context->mode->isCreate()) {
            return app(SubmissionEntityAccess::class)->personLabels($context->submitter, $values);
        }

        return array_intersect_key($this->personOptions($context), array_flip($values));
    }

    private function personLabel(EventContributionFormContext $context, mixed $value): ?string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return $this->personLabels($context, [$value])[$value] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function languageOptions(EventContributionFormContext $context): array
    {
        if ($context->mode->isCreate()) {
            return $this->options->languageOptions();
        }

        return $this->withSelectedLabels(app(SelectionCatalog::class)->languageOptions('id'), $this->selectedLanguageIds($context), static fn (array $ids): array => app(SelectionCatalog::class)->languageOptions('id'));
    }

    /**
     * @return array<string, string>
     */
    private function seriesOptions(EventContributionFormContext $context): array
    {
        if (! $context->mode->isCreate()) {
            return app(SeriesAuthoring::class)->titleOptions();
        }

        return Series::query()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @param array<string, string> $keys */
    private function disablesEventFormat(string $value, Get $get, EventContributionFormContext $context, array $keys): bool
    {
        $storedFormat = $this->eventFor($context)?->delivery_mode;
        $storedFormat = $storedFormat instanceof \BackedEnum ? $storedFormat->value : $storedFormat;

        if ($value === EventFormat::Physical->value
            || (is_string($storedFormat) && $storedFormat !== '' && $value === $storedFormat)) {
            return false;
        }

        return EventContributionFormRules::hasCommunityCategorySelection(
            $get($keys[EventContributionFormFields::EVENT_CATEGORY_IDS]),
        );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private function taxonomyLabels(array $values): array
    {
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
            $labels = array_merge($labels, EventTerm::query()->whereIn('id', $uuids)->pluck('name', 'id')->all());
        }

        return array_map(e(...), $labels);
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $selectedIds
     * @param  Closure(list<string>): array<string, string>  $labels
     * @return array<string, string>
     */
    private function withSelectedLabels(array $options, array $selectedIds, Closure $labels): array
    {
        $missing = array_values(array_diff($selectedIds, array_map(strval(...), array_keys($options))));

        if ($missing === []) {
            return $options;
        }

        return array_merge($options, array_intersect_key($labels($missing), array_flip($missing)));
    }

    /**
     * @return list<string>
     */
    private function selectedPersonIds(EventContributionFormContext $context): array
    {
        $event = $this->eventFor($context);

        return EventContributionFormRules::normalizeStringList([
            ...($event?->keyPeople->pluck('involveable_id')->all() ?? []),
            $event?->primaryOrganizerInvolvement?->involveable_id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function selectedLanguageIds(EventContributionFormContext $context): array
    {
        return $this->eventFor($context)?->languages
            ?->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all() ?? [];
    }

    private function eventFor(EventContributionFormContext $context): ?Event
    {
        $eventId = $context->eventId;

        if ($eventId === null || $eventId === '') {
            return null;
        }

        if (! array_key_exists($eventId, $this->events)) {
            $event = Event::query()
                ->with(['keyPeople', 'languages', 'primaryOrganizerInvolvement'])
                ->find($eventId);

            $this->events[$eventId] = $event instanceof Event ? $event : null;
        }

        return $this->events[$eventId];
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function resolvedLocationInstitutionId(Get $get, array $keys): ?string
    {
        $organizerKind = EventContributionFormRules::selectedPrimaryOrganizerKind(
            $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_KIND]),
            $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
        );

        if ($organizerKind === 'institution' && $get($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION]) !== false) {
            return $this->normalizedString($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_INSTITUTION_ID]))
                ?? $this->normalizedString($get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]));
        }

        return $get($keys[EventContributionFormFields::LOCATION_TYPE]) === 'institution'
            ? $this->normalizedString($get($keys[EventContributionFormFields::LOCATION_INSTITUTION_ID]))
            : null;
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function requiresSeparateLocationChoice(Get $get, array $keys): bool
    {
        return EventContributionFormRules::selectedPrimaryOrganizerKind(
            $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_KIND]),
            $get($keys[EventContributionFormFields::PRIMARY_ORGANIZER_ID]),
        ) === 'person'
            || ! $get($keys[EventContributionFormFields::LOCATION_SAME_AS_INSTITUTION]);
    }

    private function isOnlineEventFormat(mixed $eventFormat): bool
    {
        $normalizedEventFormat = $eventFormat instanceof EventFormat
            ? $eventFormat
            : EventFormat::tryFrom((string) $eventFormat);

        return $normalizedEventFormat === EventFormat::Online;
    }

    private function endTimePrecedesStartTime(string $endTime, string $startTime): bool
    {
        $startParts = explode(':', $startTime);
        $endParts = explode(':', $endTime);

        $startMinutes = ((int) $startParts[0]) * 60 + ((int) ($startParts[1] ?? 0));
        $endMinutes = ((int) $endParts[0]) * 60 + ((int) ($endParts[1] ?? 0));

        return $endMinutes <= $startMinutes;
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeNullableUuid(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && Str::isUuid($value) ? $value : null;
    }

    private function resolvedSubmissionCountryId(Get $get): ?string
    {
        return app(SubmissionTimingPolicy::class)->resolveSubmissionCountryId(
            $get(EventContributionFormFields::SUBMISSION_COUNTRY_ID),
            'MY',
        );
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
}
