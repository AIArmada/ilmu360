<?php

namespace App\Filament\Resources\Persons\Schemas;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Persons\Enums\Gender;
use App\Enums\SpeakerStatus;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Models\User;
use App\Support\Submission\PublicSubmissionLockService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                                        Section::make(__('Titles & Credentials'))
                                            ->description(__('Titles, credentials, and affiliations are managed via the institution relation manager. Add a person to an institution with the appropriate position and role.'))
                                            ->compact(),
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
                                            ->imageAspectRatio('3:4')
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->imageEditorAspectRatioOptions(['3:4'])
                                            ->automaticallyCropImagesToAspectRatio()
                                            ->responsiveImages()
                                            ->conversion('card')
                                            ->helperText(__('Primary portrait (3:4 ratio).')),
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
                                        showCountryField: false,
                                        requireCountryField: false,
                                    ))
                                    ->columns(2),
                            ]),
                        Tab::make(__('Pendidikan'))
                            ->icon(Heroicon::AcademicCap)
                            ->schema([
                                Section::make(__('Education'))
                                    ->description(__('Education history is managed via the institution relation manager.')),
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
                                                Select::make('type')
                                                    ->label(__('Type'))
                                                    ->options(ContactMethodType::options())
                                                    ->required()
                                                    ->live(),
                                                ...SharedFormSchema::contactValueFields(),
                                                Select::make('purpose')
                                                    ->label(__('Purpose'))
                                                    ->options(ContactPurpose::options())
                                                    ->default(ContactPurpose::General->value)
                                                    ->required(),
                                                Toggle::make('is_public')
                                                    ->label(__('Public'))
                                                    ->default(true),
                                            ])
                                            ->columns(4)
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
                                                Select::make('platform')
                                                    ->label(__('Platform'))
                                                    ->options(SocialPlatform::options())
                                                    ->searchable()
                                                    ->required()
                                                    ->columnSpan(1),
                                                TextInput::make('handle')
                                                    ->label(__('Handle'))
                                                    ->requiredWithout('url')
                                                    ->maxLength(255)
                                                    ->placeholder(__('@username / https://...'))
                                                    ->columnSpan(1),
                                                TextInput::make('url')
                                                    ->label(__('URL'))
                                                    ->requiredWithout('handle')
                                                    ->url()
                                                    ->maxLength(255)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2)
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
