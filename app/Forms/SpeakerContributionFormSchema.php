<?php

namespace App\Forms;

use App\Enums\Gender;
use App\Enums\Honorific;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use App\Forms\Components\Select as QuickAddSelect;
use App\Models\Institution;
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
use Nnjeim\World\Models\Language;

class SpeakerContributionFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function components(
        bool $includeMedia = true,
        ?string $addressStatePath = null,
        bool $regionOnlyAddress = true,
        ?bool $showCountryField = null,
    ): array {
        $publicCountryId = SharedFormSchema::preferredPublicCountryId();
        $showCountryField ??= false;

        $components = [
            Section::make(__('Profil Penceramah'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Speaker Name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('gender')
                        ->label(__('Gender'))
                        ->options(Gender::class)
                        ->default(Gender::Male->value)
                        ->required(),
                    Toggle::make('is_freelance')
                        ->label(__('Penceramah Bebas'))
                        ->default(false)
                        ->live(),
                    TextInput::make('job_title')
                        ->label(__('Job Title'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('is_freelance')),
                    Select::make('honorific')
                        ->label(__('Honorific'))
                        ->options(Honorific::class)
                        ->multiple()
                        ->preload()
                        ->searchable(),
                    Select::make('pre_nominal')
                        ->label(__('Pre-nominal'))
                        ->options(PreNominal::class)
                        ->multiple()
                        ->preload()
                        ->searchable(),
                    Select::make('post_nominal')
                        ->label(__('Post-nominal'))
                        ->options(PostNominal::class)
                        ->multiple()
                        ->preload()
                        ->searchable(),
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
                                defaultCountryId: $publicCountryId,
                                requireCountryField: false,
                            )
                            : [SharedFormSchema::regionAddressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $publicCountryId,
                                requireCountryField: false,
                            )])
                        : ($addressStatePath === null
                            ? SharedFormSchema::addressFields(
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $publicCountryId,
                                requireCountryField: false,
                            )
                            : [SharedFormSchema::addressGroup(
                                statePath: $addressStatePath,
                                includeCountryField: true,
                                showCountryField: $showCountryField,
                                defaultCountryId: $publicCountryId,
                                requireCountryField: false,
                            )])),
                ])
                ->columns($addressStatePath === null ? 2 : 1),
            Section::make(__('Education'))
                ->schema([
                    Repeater::make('qualifications')
                        ->label(__('Qualifications'))
                        ->default([])
                        ->schema([
                            TextInput::make('institution')
                                ->label(__('Institution'))
                                ->required(),
                            TextInput::make('degree')
                                ->label(__('Degree / Level'))
                                ->required(),
                            TextInput::make('field')
                                ->label(__('Field of Study')),
                            TextInput::make('year')
                                ->label(__('Year'))
                                ->numeric()
                                ->length(4),
                        ])
                        ->columns(2),
                ]),
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
                        SpatieMediaLibraryFileUpload::make('cover')
                            ->label(__('Cover Image'))
                            ->collection('cover')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('4:5')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['4:5'])
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
                        ->options(fn (): array => Institution::query()
                            ->whereIn('status', ['verified', 'pending'])
                            ->orderBy('name')
                            ->get(['id', 'name', 'nickname'])
                            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
                            ->all())
                        ->searchable()
                        ->preload()
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
}
