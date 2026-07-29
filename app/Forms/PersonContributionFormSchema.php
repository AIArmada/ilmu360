<?php

namespace App\Forms;

use AIArmada\Persons\Enums\Gender;
use App\Forms\Components\Select as QuickAddSelect;
use App\Models\Institution;
use App\Models\Language;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
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
    ): array {
        $showCountryField ??= true;

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
                        ->options(fn (): array => Institution::query()
                            ->whereIn('status', ['verified', 'pending'])
                            ->orderBy('name')
                            ->with('names')
                            ->get(['id', 'name'])
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
