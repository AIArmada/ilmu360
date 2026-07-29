<?php

namespace App\Filament\Resources\Persons\Schemas;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use AIArmada\Persons\Enums\Gender;
use App\Enums\SpeakerStatus;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Models\User;
use App\Support\Submission\PublicSubmissionLockService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PersonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('PersonEditTabs')
                    ->id('person-edit-tabs')
                    ->persistTab()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Profil'))
                            ->icon(Heroicon::User)
                            ->schema([
                                Section::make(__('Profile'))
                                    ->components([
                                        TextInput::make('name')
                                            ->label(__('Name'))
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('gender')
                                            ->label(__('Gender'))
                                            ->options(Gender::class)
                                            ->default(Gender::Male->value)
                                            ->required(),
                                        Placeholder::make('titles_summary')
                                            ->label(__('Titles'))
                                            ->content(fn (?Person $record): string => $record?->titleAssignments()
                                                ->with('title')
                                                ->get()
                                                ->pluck('title.name')
                                                ->implode(', ') ?: __('No titles assigned')),
                                        RichEditor::make('bio')
                                            ->label(__('Biography'))
                                            ->json()
                                            ->columnSpanFull()
                                            ->placeholder(__('Share a short biography')),

                                        Select::make('languages')
                                            ->label(__('Languages'))
                                            ->relationship('languages', 'name')
                                            ->multiple()
                                            ->preload()
                                            ->searchable(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make(__('Media'))
                            ->icon(Heroicon::Photo)
                            ->schema([
                                Section::make(__('Media'))
                                    ->components([
                                        SpatieMediaLibraryFileUpload::make('avatar')
                                            ->label(__('Avatar'))
                                            ->collection('avatar')
                                            ->image()
                                            ->imageEditor()
                                            ->circleCropper()
                                            ->avatar()
                                            ->conversion('thumb')
                                            ->helperText(__('Person photo (recommended: 400x400)')),
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
                                            ->helperText(__('Primary portrait (1:1 ratio).')),
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
                                            ->collection('cover')
                                            ->label(__('Cover Image'))
                                            ->image()
                                            ->imageEditor()
                                            ->imageAspectRatio('16:9')
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->imageEditorAspectRatioOptions(['16:9'])
                                            ->automaticallyCropImagesToAspectRatio()
                                            ->responsiveImages()
                                            ->conversion('banner')
                                            ->helperText(__('Cover featured image')),
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
                            ]),
                        Tab::make(__('Lokasi'))
                            ->icon(Heroicon::MapPin)
                            ->schema([
                                Section::make(__('Location / Base'))
                                    ->statePath('address')
                                    ->components(SharedFormSchema::regionAddressFields(
                                        includeCountryField: true,
                                        showCountryField: true,
                                        requireCountryField: true,
                                    ))
                                    ->columns(2),
                            ]),
                        Tab::make(__('Hubungan'))
                            ->icon(Heroicon::ChatBubbleLeftRight)
                            ->schema([
                                Section::make(__('Contact'))
                                    ->components([
                                        Repeater::make('contactMethods')
                                            ->label(__('Contact Details'))
                                            ->relationship()
                                            ->default([])
                                            ->schema([
                                                Grid::make(4)->schema([
                                                    Select::make('type')
                                                        ->label(__('Type'))
                                                        ->options(ContactMethodType::options())
                                                        ->required()
                                                        ->live(),
                                                    TextInput::make('label')
                                                        ->label(__('Label'))
                                                        ->maxLength(255)
                                                        ->placeholder(__('Admin, Office, Support')),
                                                    Select::make('purpose')
                                                        ->label(__('Purpose'))
                                                        ->options(ContactPurpose::options())
                                                        ->default(ContactPurpose::General->value)
                                                        ->required(),
                                                    Toggle::make('is_primary')
                                                        ->label(__('Primary'))
                                                        ->fixIndistinctState(),
                                                ]),
                                                ...SharedFormSchema::contactValueFields(),
                                                Grid::make(2)->schema([
                                                    Toggle::make('is_public')
                                                        ->label(__('Public'))
                                                        ->default(true),
                                                ]),
                                            ])
                                            ->orderColumn('sort_order')
                                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForFill($data))
                                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                                            ->itemLabel(fn (array $state): string => SharedFormSchema::contactItemLabel($state)),
                                    ]),
                                Section::make(__('Social Media'))
                                    ->components([
                                        Repeater::make('socialProfiles')
                                            ->label(__('Social Media Links'))
                                            ->relationship()
                                            ->default([])
                                            ->schema([
                                                Grid::make(2)->schema([
                                                    Select::make('platform')
                                                        ->label(__('Platform'))
                                                        ->options(SocialPlatform::options())
                                                        ->searchable()
                                                        ->required()
                                                        ->live(),
                                                    TextInput::make('label')
                                                        ->label(__('Label'))
                                                        ->maxLength(255)
                                                        ->placeholder(__('Main page, Official channel')),
                                                ]),
                                                TextInput::make('handle')
                                                    ->label(__('Username / Handle'))
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->placeholder(__('username or https://...'))
                                                    ->live()
                                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                                        if ($state === null || $state === '' || ! str_contains($state, '://')) {
                                                            return;
                                                        }
                                                        $platform = $get('platform');
                                                        if ($platform === null || $platform === '') {
                                                            return;
                                                        }
                                                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : $platform;
                                                        $extracted = app(SocialProfileConfig::class)->extractHandle($platformValue, $state);
                                                        if ($extracted !== null) {
                                                            $set('handle', $extracted);
                                                        }
                                                    })
                                                    ->visible(function (Get $get): bool {
                                                        $platform = $get('platform');
                                                        if ($platform === null || $platform === '') {
                                                            return false;
                                                        }
                                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                                    }),
                                                Placeholder::make('profile_url')
                                                    ->label(__('Profile URL'))
                                                    ->content(function (Get $get): ?string {
                                                        $platform = $get('platform');
                                                        $handle = $get('handle');
                                                        if (! is_string($handle) || $handle === '') {
                                                            return null;
                                                        }
                                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                                        return app(SocialProfileConfig::class)->buildUrl($value, $handle);
                                                    })
                                                    ->columnSpanFull()
                                                    ->visible(function (Get $get): bool {
                                                        $platform = $get('platform');
                                                        if ($platform === null || $platform === '') {
                                                            return false;
                                                        }
                                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                                    }),
                                                TextInput::make('url')
                                                    ->label(__('URL'))
                                                    ->required()
                                                    ->url()
                                                    ->maxLength(255)
                                                    ->columnSpanFull()
                                                    ->visible(function (Get $get): bool {
                                                        $platform = $get('platform');
                                                        if ($platform === null || $platform === '') {
                                                            return false;
                                                        }
                                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                                        return $value === SocialPlatform::Website->value || $value === SocialPlatform::Other->value;
                                                    }),
                                                Grid::make(2)->schema([
                                                    Toggle::make('is_primary')
                                                        ->label(__('Primary'))
                                                        ->fixIndistinctState(),
                                                    Toggle::make('is_public')
                                                        ->label(__('Public'))
                                                        ->default(true),
                                                ]),
                                            ])
                                            ->orderColumn('sort_order')
                                            ->itemLabel(function (array $state): ?string {
                                                $platform = $state['platform'] ?? null;

                                                if ($platform instanceof SocialPlatform) {
                                                    return $platform->label();
                                                }

                                                if (is_string($platform) && $platform !== '') {
                                                    return SocialPlatform::tryFrom($platform)?->label() ?? $platform;
                                                }

                                                return null;
                                            }),
                                    ]),
                            ]),
                        Tab::make(__('Status'))
                            ->icon(Heroicon::ShieldCheck)
                            ->schema([
                                Section::make(__('Status'))
                                    ->components([
                                        Select::make('status')
                                            ->label(__('Status'))
                                            ->options([
                                                'pending' => __('Pending'),
                                                'verified' => __('Verified'),
                                                'rejected' => __('Rejected'),
                                                'inactive' => __('Inactive'),
                                            ])
                                            ->required(),
                                        Select::make('speaker_status')
                                            ->label(__('Speaker Status'))
                                            ->placeholder(__('Not a speaker'))
                                            ->options(SpeakerStatus::class)
                                            ->helperText(__('Mark as active speaker to feature on the penceramah directory.')),
                                        Toggle::make('allow_public_event_submission')
                                            ->label(__('Allow Public Event Submission'))
                                            ->disabled(fn (?Person $record, string $operation): bool => ! self::canManagePublicSubmissionToggle($record, $operation))
                                            ->helperText(fn (?Person $record, string $operation): string => self::publicSubmissionHelperText($record, $operation)),
                                    ])
                                    ->columns(1),
                            ]),
                    ]),
            ]);
    }

    private static function canManagePublicSubmissionToggle(?Person $record, string $operation): bool
    {
        if ($operation !== 'edit' || ! $record instanceof Person) {
            return false;
        }

        if (! self::hasPublicSubmissionToggleAccess()) {
            return false;
        }

        if (! $record->allow_public_event_submission) {
            return true;
        }

        return app(PublicSubmissionLockService::class)->personEligibility($record)->eligible;
    }

    private static function hasPublicSubmissionToggleAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    private static function publicSubmissionHelperText(?Person $record, string $operation): string
    {
        if ($operation !== 'edit') {
            return __('Public event submission defaults to enabled on create.');
        }

        if (! $record instanceof Person) {
            return __('Use this toggle to control whether the public can submit events for this person.');
        }

        if (! self::hasPublicSubmissionToggleAccess()) {
            return __('Only global admins can change this setting.');
        }

        if (! $record->allow_public_event_submission) {
            return __('Enabled means anyone can submit. Disabled means only members can submit.');
        }

        $eligibility = app(PublicSubmissionLockService::class)->personEligibility($record);

        if ($eligibility->eligible) {
            return __('Turn this off to shift submission responsibility entirely to members.');
        }

        return __('Cannot turn this off yet: ').implode(' ', $eligibility->reasons);
    }
}
