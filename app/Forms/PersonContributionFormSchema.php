<?php

namespace App\Forms;

use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\Title;
use App\Forms\Components\Select as QuickAddSelect;
use App\Models\Institution;
use App\Models\Language;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PersonContributionFormSchema
{
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
    ): array {
        $showCountryField ??= true;

        $components = [
            Section::make(__('Profil Penceramah'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Speaker Name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('family_name')
                        ->label(__('Family name'))
                        ->maxLength(100)
                        ->helperText(__('Surname / family name used for sorting.')),
                    TextInput::make('middle_name')
                        ->label(__('Middle name'))
                        ->maxLength(100),
                    ...($includeAlternativeNames ? [
                        Repeater::make('names')
                            ->label(__('Alternative Names'))
                            ->schema([
                                Hidden::make('id'),
                                Select::make('name_type')
                                    ->options(PersonNameType::class)
                                    ->required(),
                                TextInput::make('full_name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('language_code')
                                    ->maxLength(10)
                                    ->default('ms'),
                                Toggle::make('is_primary')
                                    ->default(false),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add name'))
                            ->columnSpanFull(),
                    ] : []),
                    ...($useTitleMultiSelect ? [
                        Select::make('title_ids')
                            ->label(__('Titles'))
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => self::titleSearchOptions(''))
                            ->preload()
                            ->getSearchResultsUsing(fn (string $search): array => self::titleSearchOptions($search))
                            ->getOptionLabelUsing(fn (string $value): ?string => self::titleLabels([$value])[$value] ?? null)
                            ->getOptionLabelsUsing(fn (array $values): array => self::titleLabels($values))
                            ->columnSpanFull(),
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
                                    ->required(),
                                DatePicker::make('date_awarded')
                                    ->label(__('Date Awarded')),
                                DatePicker::make('date_expired')
                                    ->label(__('Date Expired')),
                                Select::make('status')
                                    ->options(AssignmentStatus::class)
                                    ->default(AssignmentStatus::Active->value)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add title'))
                            ->columnSpanFull(),
                    ]),
                    Select::make('gender')
                        ->label(__('Gender'))
                        ->options(Gender::class)
                        ->default(Gender::Male->value)
                        ->required(),
                    RichEditor::make('bio')
                        ->label(__('Biography'))
                        ->json()
                        ->columnSpanFull(),
                    Select::make('language_ids')
                        ->label(__('Languages'))
                        ->options(fn (): array => Language::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2),
            Section::make($regionOnlyAddress ? __('Address') : __('Location / Base'))
                ->schema([
                    ...($regionOnlyAddress
                        ? ($addressStatePath === null
                            ? SharedFormSchema::regionAddressFields(
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                requireCountryField: false,
                            )
                            : [SharedFormSchema::regionAddressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                requireCountryField: false,
                            )])
                        : ($addressStatePath === null
                            ? SharedFormSchema::addressFields(
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                requireCountryField: false,
                            )
                            : [SharedFormSchema::addressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                requireCountryField: false,
                            )])),
                ])
                ->columns($addressStatePath === null ? 2 : 1),
            Section::make(__('Contact'))
                ->schema([
                    SharedFormSchema::contactsRepeater(),
                ]),
            Section::make(__('Social Media'))
                ->schema([
                    SharedFormSchema::socialMediaRepeater(__('Add social media links for this speaker')),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, 3, 0, [
                Section::make(__('Profile Photo & Media'))
                    ->description(__('Upload a clear square profile photo first. Cover and gallery images are optional.'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('avatar')
                            ->label(__('Avatar'))
                            ->collection('avatar')
                            ->image()
                            ->imageEditor()
                            ->circleCropper()
                            ->avatar()
                            ->conversion('thumb')
                            ->helperText(__('Recommended: a clear square image, at least 400x400px.')),
                        SpatieMediaLibraryFileUpload::make('main')
                            ->label(__('Main Photo'))
                            ->collection('main')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('1:1')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['1:1'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->responsiveImages()
                            ->conversion('thumb')
                            ->helperText(__('Primary speaker portrait (1:1 ratio).')),
                        SpatieMediaLibraryFileUpload::make('profile')
                            ->label(__('Profile Photo'))
                            ->collection('profile')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('3:4')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['3:4'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->responsiveImages()
                            ->conversion('profile_thumb')
                            ->helperText(__('Speaker portrait (3:4 ratio).')),
                        SpatieMediaLibraryFileUpload::make('cover')
                            ->label(__('Cover Image'))
                            ->collection('cover')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('3:4')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['3:4'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->responsiveImages()
                            ->conversion('banner')
                            ->helperText(__('Cover image for speaker profile')),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->label(__('Gallery'))
                            ->collection('gallery')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->responsiveImages()
                            ->conversion('gallery_thumb')
                            ->helperText(__('Additional images')),
                    ])
                    ->columns(2),
            ]);
        }

        array_splice($components, 2, 0, [
            Section::make(__('Affiliated Institution'))
                ->schema([
                    QuickAddSelect::make('institution_id')
                        ->label(__('Affiliated Institution'))
                        ->searchable()
                        ->options(fn (): array => self::institutionSearchOptions(''))
                        ->preload()
                        ->getSearchResultsUsing(fn (string $search): array => self::institutionSearchOptions($search))
                        ->getOptionLabelUsing(fn (string $value): ?string => self::institutionLabels([$value])[$value] ?? null)
                        ->getOptionLabelsUsing(fn (array $values): array => self::institutionLabels($values))
                        ->live()
                        ->closeOnSelect()
                        ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                        ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => InstitutionFormSchema::createOptionUsing($data, $schema)),
                    TextInput::make('institution_position')
                        ->label(__('Position'))
                        ->maxLength(255)
                        ->placeholder(__('e.g., Imam, Mudir, Committee Member'))
                        ->visible(fn (Get $get): bool => filled($get('institution_id'))),
                ])
                ->columns(2),
        ]);

        return $components;
    }

    /**
     * @return array<string, string>
     */
    private static function titleOptions(): array
    {
        return Title::query()
            ->with('category')
            ->get()
            ->sortBy(fn (Title $title): array => [
                $title->category->sort_order,
                $title->sort_order,
                $title->name,
            ])
            ->mapWithKeys(fn (Title $title): array => [(string) $title->getKey() => $title->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function titleSearchOptions(string $search): array
    {
        $search = trim($search);

        return Title::query()
            ->with('category')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('short_form', 'like', "%{$search}%");
            }))
            ->limit(50)
            ->get()
            ->sortBy(fn (Title $title): array => [
                $title->category->sort_order,
                $title->sort_order,
                $title->name,
            ])
            ->mapWithKeys(fn (Title $title): array => [(string) $title->getKey() => $title->name])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private static function titleLabels(array $values): array
    {
        return Title::query()
            ->whereKey(array_map(static fn (mixed $value): string => (string) $value, $values))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $label, mixed $id): array => [(string) $id => (string) $label])
            ->all();
    }

    /** @return array<string, string> */
    private static function institutionSearchOptions(string $search): array
    {
        $search = trim($search);
        $searchPattern = '%'.mb_strtolower($search, 'UTF-8').'%';

        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where(function ($query) use ($searchPattern): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$searchPattern])
                    ->orWhereHas('names', fn ($query) => $query->whereRaw('LOWER(full_name) LIKE ?', [$searchPattern]));
            })
            ->with('names:id,institution_id,full_name,is_primary')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->getKey() => $institution->display_name])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private static function institutionLabels(array $values): array
    {
        return Institution::query()
            ->whereKey(array_map(static fn (mixed $value): string => (string) $value, $values))
            ->with('names:id,institution_id,full_name,is_primary')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->getKey() => $institution->display_name])
            ->all();
    }
}
