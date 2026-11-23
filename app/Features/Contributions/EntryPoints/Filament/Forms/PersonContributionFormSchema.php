<?php

namespace App\Features\Contributions\EntryPoints\Filament\Forms;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\FilamentContacting\Schemas\ContactMethodRepeaterSchema;
use AIArmada\FilamentContacting\Schemas\SocialProfileRepeaterSchema;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use App\Features\Contributions\Api\Filament\PersonContributionSchema;
use App\Features\Institutions\Api\Filament\InstitutionQuickAdd;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Platform\Language\MalaysiaLanguageCatalog;
use App\Platform\Ui\Forms\AddressFields;
use App\Platform\Ui\Forms\Select as SelectWithCloseOnSelect;
use App\Shared\Cache\SelectionCatalogCache;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PersonContributionFormSchema implements PersonContributionSchema
{
    /**
     * @return array<int, Component>
     */
    public function contributionComponents(
        bool $includeMedia = true,
        ?string $addressStatePath = null,
        bool $regionOnlyAddress = true,
        ?bool $showCountryField = null,
        bool $includeAlternativeNames = true,
        bool $useTitleMultiSelect = false,
        bool $splitProfileSections = false,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
        bool $includeWazeUrlField = true,
    ): array {
        return self::components(
            $includeMedia,
            $addressStatePath,
            $regionOnlyAddress,
            $showCountryField,
            $includeAlternativeNames,
            $useTitleMultiSelect,
            $splitProfileSections,
            $defaultCountryId,
            $requireCountryField,
            $includeWazeUrlField,
        );
    }

    /**
     * @return array<int, Component>
     */
    public static function components(
        bool $includeMedia = true,
        ?string $addressStatePath = null,
        bool $regionOnlyAddress = true,
        ?bool $showCountryField = null,
        bool $includeAlternativeNames = true,
        bool $useTitleMultiSelect = false,
        bool $splitProfileSections = false,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
        bool $includeWazeUrlField = true,
    ): array {
        $showCountryField ??= true;
        $defaultCountryId ??= AddressCountry::query()->where('iso2', 'MY')->value('id');

        $titleFields = $useTitleMultiSelect ? [
            Select::make('title_ids')
                ->label(__('Titles'))
                ->placeholder(__('Select honorifics'))
                ->helperText(__('Select any honorifics commonly used by this speaker.'))
                ->multiple()
                ->searchable()
                ->options(fn (): array => self::titleOptions())
                ->preload()
                ->getSearchResultsUsing(fn (string $search): array => self::titleSearchOptions($search))
                ->getOptionLabelUsing(fn (string $value): ?string => self::titleLabels([$value])[$value] ?? null)
                ->getOptionLabelsUsing(fn (array $values): array => self::titleLabels($values))
                ->columnSpanFull()
                ->native(false),
        ] : [
            Repeater::make('title_assignments')
                ->label(__('Titles'))
                ->schema([
                    Hidden::make('id'),
                    Select::make('title_id')
                        ->label(__('Title'))
                        ->options(self::titleOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),
                    DatePicker::make('date_awarded')
                        ->label(__('Date Awarded')),
                    DatePicker::make('date_expired')
                        ->label(__('Date Expired')),
                    Select::make('status')
                        ->options(AssignmentStatus::class)
                        ->default(AssignmentStatus::Active->value)
                        ->required()
                        ->native(false),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel(__('Add title'))
                ->columnSpanFull(),
        ];

        $profileCoreFields = [
            TextInput::make('name')
                ->label(__('Speaker Name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('Use the name most people know this speaker by.')),
            TextInput::make('family_name')
                ->label(__('Family name'))
                ->maxLength(100),
            TextInput::make('middle_name')
                ->label(__('Middle name'))
                ->maxLength(100)
                ->helperText(__('Fill this in if the speaker has a middle name.')),
            Select::make('gender')
                ->label(__('Gender'))
                ->options(fn (): array => collect(Gender::cases())->mapWithKeys(
                    fn (Gender $gender): array => [$gender->value => __($gender->label())]
                )->all())
                ->required()
                ->native(false),
            ...($splitProfileSections ? $titleFields : []),
        ];

        $profileOptionalFields = [
            ...($includeAlternativeNames ? [
                Repeater::make('names')
                    ->label(__('Alternative Names'))
                    ->helperText(__('Add other names, spellings, or professional names used by this speaker.'))
                    ->schema([
                        Hidden::make('id'),
                        Select::make('name_type')
                            ->label(__('Name type'))
                            ->placeholder(__('Select name type'))
                            ->options(self::personNameTypeOptions())
                            ->required()
                            ->native(false),
                        TextInput::make('full_name')
                            ->label(__('Full name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('language_code')
                            ->label(__('Name language'))
                            ->placeholder(__('Select language'))
                            ->options(fn (): array => self::languageOptions('code'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default('ms')
                            ->disablePlaceholderSelection()
                            ->native(false),
                        Toggle::make('is_primary')
                            ->label(__('Primary name'))
                            ->helperText(__('Mark this if this should be the main displayed name.'))
                            ->fixIndistinctState()
                            ->default(false),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel(__('Add name'))
                    ->columnSpanFull(),
            ] : []),
            ...($splitProfileSections ? [] : $titleFields),
            RichEditor::make('bio')
                ->label(__('Biography'))
                ->helperText(__('Share a short background, teaching focus, or relevant experience.'))
                ->json()
                ->columnSpanFull(),
            Select::make('language_ids')
                ->label(__('Languages'))
                ->placeholder(__('Pilih bahasa'))
                ->options(fn (): array => self::languageOptions('id'))
                ->helperText(__('Select languages the speaker uses or teaches in.'))
                ->multiple()
                ->searchable()
                ->preload()
                ->native(false),
        ];

        $components = [
            Section::make($splitProfileSections ? __('Maklumat Utama') : __('Profil Penceramah'))
                ->schema($splitProfileSections ? $profileCoreFields : [...$profileCoreFields, ...$profileOptionalFields])
                ->columns(2),
            ...($splitProfileSections ? [
                Section::make(__('Maklumat Tambahan'))
                    ->schema($profileOptionalFields)
                    ->columns(2),
            ] : []),
            Section::make($regionOnlyAddress ? __('Address') : __('Location / Base'))
                ->description(__('Choose the country and region where this speaker is based.'))
                ->schema([
                    ...($regionOnlyAddress
                        ? ($addressStatePath === null
                            ? AddressFields::regionAddressFields(
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $defaultCountryId,
                                requireCountryField: $requireCountryField,
                            )
                            : [AddressFields::regionAddressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $defaultCountryId,
                                requireCountryField: $requireCountryField,
                            )])
                        : ($addressStatePath === null
                            ? AddressFields::addressFields(
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $defaultCountryId,
                                requireCountryField: $requireCountryField,
                                includeWazeUrlField: $includeWazeUrlField,
                            )
                            : [AddressFields::addressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $defaultCountryId,
                                requireCountryField: $requireCountryField,
                                includeWazeUrlField: $includeWazeUrlField,
                            )])),
                ])
                ->columns($addressStatePath === null ? 2 : 1),
            Section::make(__('Contact'))
                ->schema([
                    ContactMethodRepeaterSchema::make(helperText: __('Add a phone number, WhatsApp number, or email that people may use to contact the speaker.')),
                ]),
            Section::make(__('Social Media'))
                ->schema([
                    SocialProfileRepeaterSchema::make(helperText: __('Add social media links for this speaker')),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, 3, 0, [
                self::mediaSection(),
            ]);
        }

        $institutionFields = [
            Repeater::make('institutions')
                ->label(__('Institutions'))
                ->helperText(__('Add institutions associated with this speaker.'))
                ->schema([
                    Hidden::make('id'),
                    Hidden::make('institution_name')->dehydrated(false),
                    SelectWithCloseOnSelect::make('institution_id')
                        ->label(__('Institution'))
                        ->helperText(__('Search for and select the associated institution.'))
                        ->searchable()
                        ->options(fn (): array => self::institutionSearchOptions(''))
                        ->preload()
                        ->getSearchResultsUsing(fn (string $search): array => self::institutionSearchOptions($search))
                        ->getOptionLabelUsing(fn (string $value): ?string => self::institutionLabels([$value])[$value] ?? null)
                        ->getOptionLabelsUsing(fn (array $values): array => self::institutionLabels($values))
                        ->createOptionForm(fn (): array => app(InstitutionQuickAdd::class)->formComponents(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => app(InstitutionQuickAdd::class)->createOption($data, $schema))
                        ->required()
                        ->native(false),
                    TextInput::make('position')
                        ->label(__('Position'))
                        ->maxLength(255)
                        ->placeholder(__('e.g., Imam, Mudir, Committee Member')),
                    Toggle::make('is_primary')
                        ->label(__('Primary institution'))
                        ->helperText(__('Mark the speaker\'s main institution.'))
                        ->fixIndistinctState()
                        ->default(false),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel(__('Add institution'))
                ->itemLabel(fn (array $state): ?string => is_string($state['institution_name'] ?? null)
                    ? $state['institution_name']
                    : (isset($state['institution_id']) ? (string) $state['institution_id'] : null))
                ->columnSpanFull(),
        ];

        array_splice($components, 2, 0, [
            Section::make(__('Affiliated Institution'))
                ->schema($institutionFields)
                ->columns(1),
        ]);

        return $components;
    }

    /**
     * @param  list<string>|null  $mediaFields
     * @param  (Closure(SpatieMediaLibraryFileUpload): void)|null  $configureMedia
     */
    public static function publicTabs(
        string $id = 'person-contribution-tabs',
        bool $requireCountryField = true,
        ?array $mediaFields = null,
        ?Closure $configureMedia = null,
    ): Tabs {
        [$core, $additional, $affiliations, $location, $contact, $social] = self::components(
            includeMedia: false,
            addressStatePath: 'address',
            useTitleMultiSelect: true,
            splitProfileSections: true,
            requireCountryField: $requireCountryField,
            includeWazeUrlField: false,
        );

        $tabs = [
            Tab::make(__('Maklumat Utama'))->icon(Heroicon::User)->schema([$core]),
            Tab::make(__('Maklumat Tambahan'))->icon(Heroicon::AcademicCap)->schema([$additional]),
            Tab::make(__('Afiliasi'))->icon(Heroicon::BuildingOffice)->schema([$affiliations]),
            Tab::make(__('Lokasi'))->icon(Heroicon::MapPin)->schema([$location]),
            Tab::make(__('Hubungan'))->icon(Heroicon::ChatBubbleLeftRight)->schema([$contact, $social]),
        ];

        if ($mediaFields !== []) {
            $tabs[] = Tab::make(__('Media'))->icon(Heroicon::Photo)
                ->schema([self::mediaSection($mediaFields, $configureMedia)]);
        }

        return Tabs::make($id)->id($id)->columnSpanFull()->tabs($tabs);
    }

    /**
     * @param  list<string>|null  $mediaFields
     * @param  (Closure(SpatieMediaLibraryFileUpload): void)|null  $configureMedia
     */
    private static function mediaSection(?array $mediaFields = null, ?Closure $configureMedia = null): Section
    {
        $fields = [
            SpatieMediaLibraryFileUpload::make('avatar')
                ->label(__('Profile avatar'))
                ->collection('avatar')
                ->image()
                ->imageEditor()
                ->circleCropper()
                ->avatar()
                ->conversion('thumb')
                ->helperText(__('Use a clear square profile image for the main avatar, at least 400x400px.')),
            SpatieMediaLibraryFileUpload::make('main')
                ->label(__('Main Photo'))
                ->collection('main')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('1:1')
                ->rules(['dimensions:ratio=1/1'])
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['1:1'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('thumb')
                ->helperText(__('Primary speaker portrait for directory cards, using a 1:1 ratio.')),
            SpatieMediaLibraryFileUpload::make('profile')
                ->label(__('Profile Photo'))
                ->collection('profile')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->rules(['dimensions:ratio=3/4'])
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('profile_thumb')
                ->helperText(__('Vertical speaker portrait for the profile page, using a 3:4 ratio.')),
            SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Profile cover image'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->rules(['dimensions:ratio=16/9'])
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9', null])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('banner')
                ->helperText(__('Wide cover image for the speaker profile, using a 16:9 ratio.')),
            SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Photo gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->image()
                ->responsiveImages()
                ->conversion('gallery_thumb')
                ->helperText(__('Additional photos related to the speaker.')),
        ];

        if ($mediaFields !== null) {
            $fields = array_filter($fields, fn (SpatieMediaLibraryFileUpload $field): bool => in_array($field->getName(), $mediaFields, true));
        }

        if ($configureMedia instanceof Closure) {
            foreach ($fields as $field) {
                $configureMedia($field);
            }
        }

        return Section::make(__('Profile Photo & Media'))
            ->description(__('All media fields are optional. If available, add a clear square profile photo first; cover and gallery images are optional.'))
            ->schema(array_values($fields))
            ->columns(['default' => 1, 'sm' => 2]);
    }

    /**
     * @return array<string, string>
     */
    private static function titleOptions(): array
    {
        return app(SelectionCatalogCache::class)->titleOptions();
    }

    /**
     * @return array<string, string>
     */
    private static function languageOptions(string $key): array
    {
        return app(SelectionCatalogCache::class)->languageOptions(
            $key,
            MalaysiaLanguageCatalog::labels(),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function personNameTypeOptions(): array
    {
        return collect(PersonNameType::cases())
            ->mapWithKeys(fn (PersonNameType $type): array => [$type->value => __($type->label())])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function titleSearchOptions(string $search): array
    {
        return app(SelectionCatalogCache::class)->titleSearchOptions($search);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private static function titleLabels(array $values): array
    {
        return app(SelectionCatalogCache::class)->titleLabels($values);
    }

    /** @return array<string, string> */
    private static function institutionSearchOptions(string $search): array
    {
        return app(InstitutionLookup::class)->searchDisplayNameOptions($search);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private static function institutionLabels(array $values): array
    {
        return app(InstitutionLookup::class)->displayNamesByIds(
            array_map(static fn (mixed $value): string => (string) $value, $values),
        );
    }
}
